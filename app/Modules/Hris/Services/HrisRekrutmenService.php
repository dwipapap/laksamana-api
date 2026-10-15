<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\OfficeAccess;
use App\Modules\Account\Services\AccountService;
use App\Modules\Hris\Models\HrisLowongan;
use App\Modules\Hris\Models\HrisPelamar;
use App\Modules\Hris\Models\HrisPermintaanTenaga;
use App\Modules\Hris\Models\HrisScreening;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Recruitment (R1–R5): manpower requests (Kepala Divisi → HR → Owner),
 * vacancies, applicants with encrypted contacts and auto-expiry, AI
 * screening as advice, and hiring into draft Users.
 */
final class HrisRekrutmenService
{
    public const TAHAP = ['BARU', 'DISARING', 'INTERVIEW', 'DITAWARI', 'DITERIMA', 'DITOLAK'];

    /** Forward-only stages; DITOLAK may land from anywhere but DITERIMA. */
    public const PETA_TAHAP = [
        'BARU' => ['DISARING', 'DITOLAK'],
        'DISARING' => ['INTERVIEW', 'DITOLAK'],
        'INTERVIEW' => ['DITAWARI', 'DITOLAK'],
        'DITAWARI' => ['DITERIMA', 'DITOLAK'],
        'DITERIMA' => [],
        'DITOLAK' => [],
    ];

    public function __construct(
        private readonly HrisLingkup $lingkup,
        private readonly OfficeAccess $access,
        private readonly AccountService $account,
        private readonly CvScreener $penapis,
        private readonly HrisAuditService $audit,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    // ---------------------------------------------------------------- requests

    /** @param  array{id:string,name:string}  $me */
    public function buatPermintaan(array $me, array $in): HrisPermintaanTenaga
    {
        $uid = self::s($me['id'] ?? '');
        $divisi = self::s($in['divisi'] ?? '');
        if ($uid === '') {
            throw new HrisError('unauthenticated', 'Login required.', 401);
        }
        if ($divisi === '') {
            throw new HrisError('validation_failed', 'Permintaan butuh divisi.', 422);
        }
        if (! $this->lingkup->isHr($me)
            && ! ($this->lingkup->isHead($me) && in_array($divisi, $this->lingkup->headDivisi($me), true))) {
            throw new HrisError('forbidden', 'Permintaan tenaga diajukan Kepala Divisi atau HR.', 403);
        }
        $posisi = self::s($in['posisi'] ?? '');
        if ($posisi === '') {
            throw new HrisError('validation_failed', 'Posisi wajib diisi.', 422);
        }
        $jenis = strtoupper(self::s($in['jenis'] ?? 'TAMBAHAN'));
        if ($jenis !== 'PENGGANTI' && $jenis !== 'TAMBAHAN') {
            throw new HrisError('validation_failed', 'Jenis harus PENGGANTI atau TAMBAHAN.', 422);
        }
        $tgl = self::s($in['dibutuhkan_tgl'] ?? '');
        if ($tgl !== '' && PunchRules::tglValid($tgl) === '') {
            throw new HrisError('validation_failed', 'Tanggal dibutuhkan harus YYYY-MM-DD.', 422);
        }
        $row = HrisPermintaanTenaga::query()->create([
            'divisi' => mb_substr($divisi, 0, 64), 'posisi' => mb_substr($posisi, 0, 120),
            'jumlah' => max(1, min(100, (int) ($in['jumlah'] ?? 1))), 'jenis' => $jenis,
            'alasan' => mb_substr(self::s($in['alasan'] ?? ''), 0, 500),
            'dibutuhkan_tgl' => $tgl === '' ? null : $tgl,
            'kualifikasi' => mb_substr(self::s($in['kualifikasi'] ?? ''), 0, 2000) ?: null,
            'status' => 'MENUNGGU_HR', 'pemohon_id' => $uid,
        ]);
        $this->audit->record(self::s($me['name'] ?? ''), 'permintaan_dibuat', 'hris_permintaan_tenaga', $row->id, []);

        return $row;
    }

    /** @param  array<int,string>|null  $divisis  null = all (HR/owner) */
    public function daftarPermintaan(?array $divisis, ?string $status): array
    {
        $q = HrisPermintaanTenaga::query()->orderByDesc('created_at');
        if ($divisis !== null) {
            $q->whereIn('divisi', $divisis === [] ? ['__tidak_ada__'] : $divisis);
        }
        if (self::s($status ?? '') !== '') {
            $q->where('status', strtoupper(self::s($status)));
        }
        $out = [];
        foreach ($q->limit(200)->get() as $r) {
            $out[] = $this->bentukPermintaan($r);
        }

        return $out;
    }

    public function bentukPermintaan(HrisPermintaanTenaga $r): array
    {
        return [
            'id' => $r->id, 'divisi' => $r->divisi, 'posisi' => $r->posisi,
            'jumlah' => (int) $r->jumlah, 'jenis' => $r->jenis, 'alasan' => $r->alasan,
            'dibutuhkan_tgl' => $r->dibutuhkan_tgl?->format('Y-m-d'),
            'kualifikasi' => $r->kualifikasi, 'status' => $r->status,
            'pemohon_id' => $r->pemohon_id,
            'hr_oleh' => $r->hr_oleh, 'hr_at' => (int) $r->hr_at, 'hr_nota' => $r->hr_nota,
            'owner_oleh' => $r->owner_oleh, 'owner_at' => (int) $r->owner_at, 'owner_nota' => $r->owner_nota,
            'berubah' => (int) $r->version,
        ];
    }

    /**
     * HR step (MENUNGGU_HR → MENUNGGU_OWNER) then Owner step (→ DISETUJUI,
     * opening a vacancy). Owner = superadmin. Either may DITOLAK.
     */
    public function putusPermintaan(array $me, HrisPermintaanTenaga $row, string $status, string $nota): HrisPermintaanTenaga
    {
        $nama = self::s($me['name'] ?? '');
        $status = strtoupper(self::s($status));
        if ($status !== 'DISETUJUI' && $status !== 'DITOLAK') {
            throw new HrisError('validation_failed', 'Keputusan hanya boleh DISETUJUI atau DITOLAK.', 422);
        }
        $nota = mb_substr(self::s($nota), 0, 255);
        $now = PunchRules::ms();
        if ($row->status === 'MENUNGGU_HR') {
            $this->lingkup->assertHr($me, 'Memutus permintaan tenaga');
            $row->fill(['hr_oleh' => mb_substr($nama, 0, 120), 'hr_at' => $now, 'hr_nota' => $nota,
                'status' => $status === 'DISETUJUI' ? 'MENUNGGU_OWNER' : 'DITOLAK'])->save();
        } elseif ($row->status === 'MENUNGGU_OWNER') {
            if (! $this->access->isSuperadmin(self::s($me['id'] ?? ''))) {
                throw new HrisError('forbidden', 'Tahap ini menunggu Owner (superadmin).', 403);
            }
            $row->fill(['owner_oleh' => mb_substr($nama, 0, 120), 'owner_at' => $now, 'owner_nota' => $nota,
                'status' => $status])->save();
            if ($status === 'DISETUJUI') {
                $this->bukaLowongan($row);
            }
        } else {
            throw new HrisError('already_decided', 'Permintaan ini sudah diputus.', 409);
        }
        $this->audit->record($nama, 'permintaan_diputus', 'hris_permintaan_tenaga', $row->id,
            ['status' => $row->status]);

        return $row->fresh();
    }

    public function batalPermintaan(array $me, HrisPermintaanTenaga $row): HrisPermintaanTenaga
    {
        if (in_array($row->status, ['DISETUJUI', 'DITOLAK', 'DIBATALKAN'], true)) {
            throw new HrisError('validation_failed', 'Permintaan ini sudah final.', 422);
        }
        if ($row->pemohon_id !== self::s($me['id'] ?? '') && ! $this->lingkup->isHr($me)) {
            throw new HrisError('forbidden', 'Hanya pemohon atau HR yang bisa membatalkan.', 403);
        }
        $row->fill(['status' => 'DIBATALKAN'])->save();
        $this->audit->record(self::s($me['name'] ?? ''), 'permintaan_dibatalkan', 'hris_permintaan_tenaga', $row->id, []);

        return $row->fresh();
    }

    private function bukaLowongan(HrisPermintaanTenaga $row): HrisLowongan
    {
        $ada = HrisLowongan::query()->where('permintaan_id', $row->id)->first();
        if ($ada) {
            return $ada;
        }

        return HrisLowongan::query()->create(['permintaan_id' => $row->id,
            'posisi' => $row->posisi, 'kriteria' => $row->kualifikasi, 'status' => 'BUKA']);
    }

    // ---------------------------------------------------------------- vacancies

    public function daftarLowongan(bool $termasukTutup): array
    {
        $q = HrisLowongan::query()->orderByDesc('created_at');
        if (! $termasukTutup) {
            $q->where('status', 'BUKA');
        }
        $out = [];
        foreach ($q->limit(200)->get() as $r) {
            $out[] = $this->bentukLowongan($r);
        }

        return $out;
    }

    public function bentukLowongan(HrisLowongan $r): array
    {
        return ['id' => $r->id, 'permintaan_id' => $r->permintaan_id, 'posisi' => $r->posisi,
            'kriteria' => $r->kriteria, 'status' => $r->status, 'berubah' => (int) $r->version,
            'pelamar' => HrisPelamar::query()->where('lowongan_id', $r->id)->count()];
    }

    public function tutupLowongan(array $me, HrisLowongan $row): HrisLowongan
    {
        $this->lingkup->assertHr($me, 'Menutup lowongan');
        $row->fill(['status' => 'TUTUP'])->save();
        $this->audit->record(self::s($me['name'] ?? ''), 'lowongan_ditutup', 'hris_lowongan', $row->id, []);

        return $row->fresh();
    }

    // ---------------------------------------------------------------- applicants

    /** @param  array{id:string,name:string}  $me */
    public function unggahPelamar(array $me, array $in): HrisPelamar
    {
        $nama = self::s($in['nama'] ?? '');
        if ($nama === '') {
            throw new HrisError('validation_failed', 'Nama pelamar wajib diisi.', 422);
        }
        $low = HrisLowongan::query()->find(self::s($in['lowongan_id'] ?? ''));
        if (! $low) {
            throw new HrisError('not_found', 'Lowongan tidak ada.', 404);
        }
        if ($low->status !== 'BUKA') {
            throw new HrisError('validation_failed', 'Lowongan ini sudah ditutup.', 422);
        }
        $b64 = is_string($in['cv'] ?? null) ? trim($in['cv']) : '';
        if ($b64 === '') {
            throw new HrisError('validation_failed', 'Berkas CV wajib diunggah.', 422);
        }
        if (str_starts_with($b64, 'data:')) {
            $pos = strpos($b64, ',');
            $b64 = $pos === false ? '' : substr($b64, $pos + 1);
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new HrisError('validation_failed', 'CV tidak valid (base64).', 422);
        }
        $cvNama = mb_substr(trim(self::s($in['cv_nama'] ?? 'cv')), 0, 180);
        ['teks' => $teks, 'catatan' => $catatan] = EkstraksiCv::dariBerkas($cvNama, $bytes);
        $redaksi = RedaksiPii::redact($teks);
        $kontak = $in['kontak'] ?? null;
        if ($kontak !== null && ! is_array($kontak)) {
            throw new HrisError('validation_failed', 'Kontak harus objek.', 422);
        }
        $key = 'cv_'.strtolower((string) Str::ulid()).'.bin';
        Storage::disk('local')->put('hris-cv/'.$key, $bytes);
        $today = PunchRules::tglWib(PunchRules::ms());
        $row = HrisPelamar::query()->create([
            'lowongan_id' => $low->id, 'nama' => mb_substr($nama, 0, 120),
            'kontak' => $kontak === null ? null : Crypt::encryptString(json_encode($kontak, JSON_UNESCAPED_UNICODE)),
            'cv_path' => $key, 'cv_nama' => $cvNama,
            'teks_cv' => $teks === '' ? null : $teks,
            'teks_redaksi' => $redaksi['teks'] === '' ? null : $redaksi['teks'],
            'tahap' => 'BARU', 'catatan' => trim($catatan) !== '' ? mb_substr('Ekstraksi: '.$catatan, 0, 500) : '',
            'hapus_setelah' => HrisBalanceService::tambahBulan($today, 12),
        ]);
        $this->audit->record(self::s($me['name'] ?? ''), 'pelamar_diupload', 'hris_pelamar', $row->id,
            ['lowongan_id' => $low->id, 'redaksi' => $redaksi['temuan']]);

        return $row;
    }

    /** @param  array<int,string>|null  $lowonganIds  null = all (HR only anyway) */
    public function daftarPelamar(string $lowonganId, ?string $tahap): array
    {
        $q = HrisPelamar::query()->orderByDesc('created_at');
        if ($lowonganId !== '') {
            $q->where('lowongan_id', $lowonganId);
        }
        if (self::s($tahap ?? '') !== '') {
            $t = strtoupper(self::s($tahap));
            if (! in_array($t, self::TAHAP, true)) {
                throw new HrisError('validation_failed', 'Tahap tidak dikenal.', 422);
            }
            $q->where('tahap', $t);
        }
        $out = [];
        foreach ($q->limit(200)->get() as $r) {
            $out[] = $this->bentukPelamar($r, false);
        }

        return $out;
    }

    public function bentukPelamar(HrisPelamar $r, bool $bukaKontak): array
    {
        $kontak = null;
        if ($bukaKontak && $r->kontak) {
            try {
                $kontak = json_decode(Crypt::decryptString($r->kontak), true);
            } catch (\Throwable) {
                $kontak = null;
            }
        }
        $screening = HrisScreening::query()->where('pelamar_id', $r->id)->orderByDesc('waktu')->first();

        return [
            'id' => $r->id, 'lowongan_id' => $r->lowongan_id, 'nama' => $r->nama,
            'kontak' => $kontak, 'ada_cv' => $r->cv_path ? true : false, 'cv_nama' => $r->cv_nama,
            'tahap' => $r->tahap, 'catatan' => $r->catatan,
            'draft_user_id' => $r->draft_user_id,
            'hapus_setelah' => $r->hapus_setelah?->format('Y-m-d'),
            'skrining' => $screening ? ['skor' => $screening->hasil['skor'] ?? null,
                'ringkasan' => $screening->hasil['ringkasan'] ?? null,
                'provider' => $screening->provider, 'model' => $screening->model,
                'waktu' => (int) $screening->waktu] : null,
            'berubah' => (int) $r->version,
        ];
    }

    public function pindahTahap(array $me, HrisPelamar $row, string $tahap, string $catatan): HrisPelamar
    {
        $tahap = strtoupper(self::s($tahap));
        if (! in_array($tahap, self::TAHAP, true)) {
            throw new HrisError('validation_failed', 'Tahap tidak dikenal.', 422);
        }
        if (! in_array($tahap, self::PETA_TAHAP[$row->tahap] ?? [], true)) {
            throw new HrisError('validation_failed', "Tahap {$row->tahap} tidak bisa ke {$tahap}.", 422);
        }
        if ($tahap === 'DITERIMA') {
            return $this->terima($me, $row, $catatan);
        }
        $row->fill(['tahap' => $tahap, 'catatan' => mb_substr(self::s($catatan), 0, 500)])->save();
        $this->audit->record(self::s($me['name'] ?? ''), 'pelamar_pindah', 'hris_pelamar', $row->id,
            ['tahap' => $tahap]);

        return $row->fresh();
    }

    /**
     * Hire: the accepted applicant becomes a draft (nonaktif) User for HR to
     * activate; B6 starts onboarding from draft_user_id.
     */
    public function terima(array $me, HrisPelamar $row, string $catatan): HrisPelamar
    {
        $nama = self::s($me['name'] ?? '');
        $low = HrisLowongan::query()->find($row->lowongan_id);
        $hasil = $this->account->saveUserCore([
            'name' => $row->nama, 'active' => false,
            'keterangan' => mb_substr('Calon: '.($low ? $low->posisi : ''), 0, 120),
        ]);
        if (empty($hasil['ok'])) {
            $err = $hasil['error'] ?? 'gagal';
            throw new HrisError('validation_failed',
                $err === 'name_taken'
                    ? 'Nama ini sudah dipakai User lain ('.($hasil['takenBy'] ?? '').'). Ganti nama pelamar dulu.'
                    : 'Draft User gagal dibuat: '.$err, 422);
        }
        $row->fill(['tahap' => 'DITERIMA', 'draft_user_id' => (string) $hasil['id'],
            'catatan' => mb_substr(self::s($catatan), 0, 500)])->save();
        $this->audit->record($nama, 'pelamar_diterima', 'hris_pelamar', $row->id,
            ['draft_user_id' => (string) $hasil['id']]);

        return $row->fresh();
    }

    /** HR manual delete (R4): file + rows, gone for good. */
    public function hapusPelamar(array $me, HrisPelamar $row): void
    {
        Storage::disk('local')->delete('hris-cv/'.$row->cv_path);
        HrisScreening::query()->where('pelamar_id', $row->id)->delete();
        $id = $row->id;
        $row->delete();
        $this->audit->record(self::s($me['name'] ?? ''), 'pelamar_dihapus', 'hris_pelamar', $id, ['manual' => true]);
    }

    /**
     * Retention (R4): permanently delete applicants past hapus_setelah
     * (file + screening + row). Runs in hris:harian.
     */
    public function hapusKedaluwarsa(string $today): int
    {
        $n = 0;
        foreach (HrisPelamar::query()->where('hapus_setelah', '<', $today)->get() as $r) {
            Storage::disk('local')->delete('hris-cv/'.$r->cv_path);
            HrisScreening::query()->where('pelamar_id', $r->id)->delete();
            $id = $r->id;
            $r->delete();
            $this->audit->record('scheduler', 'pelamar_dihapus', 'hris_pelamar', $id, ['otomatis' => true]);
            $n++;
        }

        return $n;
    }

    // ---------------------------------------------------------------- screening

    /**
     * Screen one applicant: redacted text + vacancy criteria through the
     * provider. The score is ADVICE with reasons — moving stages stays
     * human. Every result is stored with its prompt and model version.
     */
    public function tapis(HrisPelamar $row, string $by): array
    {
        if (! $row->teks_redaksi) {
            throw new HrisError('validation_failed', 'CV ini tidak punya teks untuk dinilai.', 422);
        }
        $low = HrisLowongan::query()->find($row->lowongan_id);
        $kriteria = $low && trim((string) $low->kriteria) !== ''
            ? [trim((string) $low->kriteria)] : ['Kesesuaian umum untuk posisi '.($low ? $low->posisi : '')];
        $hasil = $this->penapis->screen($row->teks_redaksi, $kriteria);
        HrisScreening::query()->create([
            'pelamar_id' => $row->id, 'provider' => $this->penapis->provider(),
            'model' => $hasil['model'], 'versi_prompt' => MetaMuseSparkScreener::VERSI_PROMPT,
            'hasil' => ['skor' => $hasil['skor'], 'alasan' => $hasil['alasan'],
                'ringkasan' => $hasil['ringkasan'],
                'prompt' => MetaMuseSparkScreener::prompt($row->teks_redaksi, $kriteria)],
            'waktu' => PunchRules::ms(),
        ]);
        if ($row->tahap === 'BARU') {
            $row->fill(['tahap' => 'DISARING'])->save();
        }
        $this->audit->record($by, 'pelamar_ditapis', 'hris_pelamar', $row->id,
            ['skor' => $hasil['skor'], 'provider' => $this->penapis->provider()]);

        return $hasil + ['pelamar_id' => $row->id];
    }

    public function riwayatTapis(HrisPelamar $row): array
    {
        $out = [];
        foreach (HrisScreening::query()->where('pelamar_id', $row->id)->orderByDesc('waktu')->get() as $s) {
            $h = is_array($s->hasil) ? $s->hasil : [];
            unset($h['prompt']);
            $out[] = ['provider' => $s->provider, 'model' => $s->model,
                'versi_prompt' => $s->versi_prompt, 'hasil' => $h, 'waktu' => (int) $s->waktu];
        }

        return $out;
    }
}
