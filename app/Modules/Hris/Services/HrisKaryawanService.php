<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Modules\Account\Services\AccountService;
use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisJabatan;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Models\HrisKaryawanRiwayat;
use Illuminate\Support\Facades\DB;

/**
 * Karyawan records (E1–E9, ADR-0012). The login (name, PIN, phone, email,
 * Tim) stays on the Office User and is created through AccountService, so
 * every old panel knows the person; HRIS keeps the HR record.
 *
 * After a write HRIS keeps two legacy views in line: the Jadwal Penempatan
 * Divisi (E5) and the User's HR columns (Jabatan, status kerja, join date,
 * Divisi name — E3). Those writes go to other databases after the core
 * commit; a failure is audited and returned as `peringatan`, never hidden.
 */
final class HrisKaryawanService
{
    /** Status kerja => the Talenta word the old screens show. */
    public const STATUS_TALENTA = ['PROBATION' => 'Probation', 'KONTRAK' => 'Contract',
        'TETAP' => 'Permanent', 'MAGANG' => 'Intern'];

    /** Columns whose changes go to hris_karyawan_riwayat. */
    private const RIWAYAT = ['divisi_id' => 'divisi', 'jabatan_id' => 'jabatan',
        'atasan_user_id' => 'atasan', 'status_kerja' => 'status_kerja'];

    /** @var array<string,array<string,mixed>>|null userId => Office User row */
    private ?array $userMap = null;

    public function __construct(
        private readonly AccountService $account,
        private readonly AccountRepository $users,
        private readonly HrisDivisiService $divisi,
        private readonly HrisJadwalSinkron $jadwal,
        private readonly HrisAuditService $audit,
        private readonly HrisMasterBawaan $bawaan,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    private static function hariIni(): string
    {
        return PunchRules::tglWib(PunchRules::ms());
    }

    // ------------------------------------------------------------------ reads

    /**
     * @param  array<int,string>|null  $scope  user ids the caller may see (null = all)
     * @return array<int,array<string,mixed>>
     */
    public function list(?array $scope, bool $pii, array $f = []): array
    {
        $q = HrisKaryawan::query()->with(['divisi', 'jabatan.level']);
        if ($scope !== null) {
            $q->whereIn('user_id', $scope === [] ? ['__'] : $scope);
        }
        $status = self::s($f['status'] ?? 'aktif');
        if ($status === 'aktif') {
            $q->where(fn ($w) => $w->whereNull('tanggal_keluar')->orWhere('tanggal_keluar', '>=', self::hariIni()));
        } elseif ($status === 'keluar') {
            $q->whereNotNull('tanggal_keluar')->where('tanggal_keluar', '<', self::hariIni());
        }
        if (($d = self::s($f['divisi'] ?? '')) !== '') {
            $q->where('divisi_id', $d);
        }
        $kepala = $this->kepalaPerDivisi();
        $cari = mb_strtolower(self::s($f['q'] ?? ''));
        $out = [];
        foreach ($q->get() as $k) {
            $row = $this->shape($k, $pii, $kepala);
            if ($cari !== '' && ! str_contains(mb_strtolower($row['nama'].' '.($row['jabatan']['nama'] ?? '')), $cari)) {
                continue;
            }
            $out[] = $row;
        }
        usort($out, fn ($a, $b) => strcmp(mb_strtolower($a['nama']), mb_strtolower($b['nama'])));

        return $out;
    }

    public function detail(string $userId, bool $pii): array
    {
        $k = $this->find($userId);
        $out = $this->shape($k, $pii, $this->kepalaPerDivisi());
        $out['bawahan'] = HrisKaryawan::query()->where('atasan_user_id', $k->user_id)
            ->whereNull('tanggal_keluar')->pluck('user_id')->map(fn ($u) => ['user_id' => $u, 'nama' => $this->nama($u)])->all();
        $out['riwayat'] = HrisKaryawanRiwayat::query()->where('karyawan_id', $k->id)
            ->orderByDesc('berlaku_dari')->orderByDesc('created_at')->get()
            ->map(fn (HrisKaryawanRiwayat $r) => [
                'kolom' => $r->kolom, 'dari' => $r->label_lama, 'ke' => $r->label_baru,
                'berlaku_dari' => $r->berlaku_dari->format('Y-m-d'), 'catatan' => $r->catatan,
                'oleh' => $r->dibuat_oleh,
            ])->all();

        return $out;
    }

    public function find(string $userId): HrisKaryawan
    {
        return HrisKaryawan::query()->with(['divisi', 'jabatan.level'])->where('user_id', self::s($userId))->first()
            ?? throw new HrisError('not_found', 'Karyawan tidak ada.', 404);
    }

    /** Active Office Users without a Karyawan record ("Jadikan karyawan"). */
    public function calon(): array
    {
        $ada = array_flip(HrisKaryawan::withTrashed()->pluck('user_id')->all());
        $out = [];
        foreach ($this->userMap() as $uid => $u) {
            if (! isset($ada[$uid]) && (int) ($u['active'] ?? 0) === 1) {
                $out[] = ['user_id' => $uid, 'nama' => (string) ($u['name'] ?? $uid), 'tim' => (string) ($u['keterangan'] ?? '')];
            }
        }
        usort($out, fn ($a, $b) => strcmp(mb_strtolower($a['nama']), mb_strtolower($b['nama'])));

        return $out;
    }

    /**
     * The org chart (E8): one node per employed Karyawan with its effective
     * superior. With $akar, only that person and everyone below them.
     *
     * @return array<int,array<string,mixed>>
     */
    public function struktur(?string $akar = null): array
    {
        $kepala = $this->kepalaPerDivisi();
        $nodes = [];
        foreach (HrisKaryawan::query()->with(['divisi', 'jabatan.level'])
            ->where(fn ($w) => $w->whereNull('tanggal_keluar')->orWhere('tanggal_keluar', '>=', self::hariIni()))->get() as $k) {
            [$atasan, $otomatis] = $this->atasanEfektif($k, $kepala);
            $nodes[$k->user_id] = ['user_id' => $k->user_id, 'nama' => $this->nama($k->user_id),
                'jabatan' => $k->jabatan?->nama, 'level_urutan' => (int) ($k->jabatan?->level?->urutan ?? 0),
                'divisi_id' => $k->divisi_id, 'divisi' => $k->divisi?->nama,
                'atasan_user_id' => $atasan, 'atasan_otomatis' => $otomatis];
        }
        // A superior outside the chart (left, or not a Karyawan) makes the node a root.
        foreach ($nodes as $id => $n) {
            if ($n['atasan_user_id'] !== null && ! isset($nodes[$n['atasan_user_id']])) {
                $nodes[$id]['atasan_user_id'] = null;
            }
        }
        if ($akar !== null) {
            $anak = [];
            foreach ($nodes as $n) {
                if ($n['atasan_user_id'] !== null) {
                    $anak[$n['atasan_user_id']][] = $n['user_id'];
                }
            }
            $keep = [];
            $antre = isset($nodes[$akar]) ? [$akar] : [];
            while ($antre) {
                $id = array_shift($antre);
                if (isset($keep[$id])) {
                    continue;
                }
                $keep[$id] = true;
                array_push($antre, ...($anak[$id] ?? []));
            }
            $nodes = array_intersect_key($nodes, $keep);
            if (isset($nodes[$akar])) {
                $nodes[$akar]['atasan_user_id'] = null;
            }
        }

        return array_values($nodes);
    }

    /** User ids in the Divisi that $userId heads (either kind), self included. */
    public function anggotaKepala(string $userId): array
    {
        $divs = $this->divisi->petaKepala()[$userId] ?? [];
        $ids = $divs === [] ? [] : HrisKaryawan::query()->whereIn('divisi_id', $divs)->pluck('user_id')->all();

        return array_values(array_unique([$userId, ...$ids]));
    }

    public function isKepala(string $userId): bool
    {
        return isset($this->divisi->petaKepala()[$userId]);
    }

    // ----------------------------------------------------------------- writes

    /** E3: one form creates the Office User and the Karyawan record. */
    public function create(array $in, string $by): array
    {
        $this->bawaan->pastikan();
        $nama = self::s($in['nama'] ?? '');
        if ($nama === '') {
            throw new HrisError('validation_failed', 'Nama wajib diisi.', 422);
        }
        $isi = $this->isi($in, null);
        $res = $this->account->saveUserCore(['name' => $nama, 'pin' => self::s($in['pin'] ?? ''),
            'keterangan' => self::s($in['tim'] ?? ''), 'noHp' => self::s($in['no_hp'] ?? ''),
            'email' => self::s($in['email'] ?? '')] + $this->kolomHr($isi));
        if (! ($res['ok'] ?? false)) {
            throw new HrisError((string) ($res['error'] ?? 'rejected'), self::pesanAkun($res), 422);
        }
        $uid = (string) $res['id'];
        try {
            $k = HrisKaryawan::query()->create(['user_id' => $uid] + $isi);
        } catch (\Throwable $e) {
            // The User exists but has no record: take the login away again.
            $this->users->setActive($uid, false);
            throw $e;
        }
        $this->audit->record($by, 'karyawan_dibuat', 'hris_karyawan', $k->id, ['user_id' => $uid]);
        $this->userMap = null;

        return $this->setelahTulis($k, $by, true);
    }

    /** "Jadikan karyawan": an existing Office User gets a Karyawan record. */
    public function jadikan(string $userId, array $in, string $by): array
    {
        $this->bawaan->pastikan();
        $userId = self::s($userId);
        $u = $this->users->userById($userId);
        if (! $u) {
            throw new HrisError('not_found', 'User tidak ada.', 404);
        }
        if (HrisKaryawan::withTrashed()->where('user_id', $userId)->exists()) {
            throw new HrisError('sudah_karyawan', 'User ini sudah karyawan.', 409);
        }
        $k = HrisKaryawan::query()->create(['user_id' => $userId] + $this->isi($in, null, $userId));
        $this->audit->record($by, 'karyawan_dibuat', 'hris_karyawan', $k->id, ['user_id' => $userId, 'dari' => 'user']);

        return $this->setelahTulis($k, $by, true);
    }

    public function update(string $userId, array $in, string $by): array
    {
        $k = $this->find($userId);
        HrisDivisiService::assertVersion($k->version, $in);
        $isi = $this->isi($in, $k);
        $berlaku = PunchRules::tglValid($in['berlaku_dari'] ?? '') ?: self::hariIni();
        $catatan = mb_substr(self::s($in['catatan'] ?? ''), 0, 255) ?: null;
        $divisiBerubah = array_key_exists('divisi_id', $isi) && $isi['divisi_id'] !== $k->divisi_id;

        DB::connection('core')->transaction(function () use ($k, $isi, $berlaku, $catatan, $by) {
            foreach (self::RIWAYAT as $col => $kolom) {
                if (! array_key_exists($col, $isi) || $isi[$col] === $k->{$col}) {
                    continue;
                }
                HrisKaryawanRiwayat::query()->create(['karyawan_id' => $k->id, 'kolom' => $kolom,
                    'nilai_lama' => $k->{$col}, 'nilai_baru' => $isi[$col],
                    'label_lama' => $this->label($kolom, $k->{$col}), 'label_baru' => $this->label($kolom, $isi[$col]),
                    'berlaku_dari' => $berlaku, 'catatan' => $catatan, 'dibuat_oleh' => mb_substr($by, 0, 120)]);
            }
            $k->fill($isi)->save();
        });
        $this->audit->record($by, 'karyawan_diubah', 'hris_karyawan', $k->id, ['kolom' => array_keys($isi)]);

        return $this->setelahTulis($k->fresh(['divisi', 'jabatan.level']), $by, $divisiBerubah);
    }

    /**
     * E9: record the exit. Subordinates and headships must be handed over
     * first. On or before today the login is taken at once; a later date is
     * done by hris:harian on that day.
     */
    public function keluar(string $userId, array $in, string $by): array
    {
        $k = $this->find($userId);
        HrisDivisiService::assertVersion($k->version, $in);
        $tgl = PunchRules::tglValid($in['tanggal'] ?? '');
        $alasan = strtoupper(self::s($in['alasan'] ?? ''));
        if ($tgl === '' || ! in_array($alasan, HrisKaryawan::ALASAN_KELUAR, true)) {
            throw new HrisError('validation_failed', 'Tanggal keluar dan alasan (RESIGN, KONTRAK_HABIS, PHK, LAINNYA) wajib.', 422);
        }
        $bawahan = HrisKaryawan::query()->where('atasan_user_id', $k->user_id)->whereNull('tanggal_keluar')->pluck('user_id')->all();
        if ($bawahan !== []) {
            throw new HrisError('masih_punya_bawahan', 'Pindahkan dulu bawahannya ke atasan lain.', 409,
                ['bawahan' => array_map(fn ($u) => ['user_id' => $u, 'nama' => $this->nama($u)], $bawahan)]);
        }
        if ($this->isKepala($k->user_id)) {
            throw new HrisError('masih_kepala_divisi', 'Ganti dulu Kepala Divisi yang dipegang orang ini.', 409);
        }
        $k->fill(['tanggal_keluar' => $tgl, 'alasan_keluar' => $alasan,
            'catatan_keluar' => mb_substr(self::s($in['catatan'] ?? ''), 0, 255) ?: null])->save();
        $this->audit->record($by, 'karyawan_keluar', 'hris_karyawan', $k->id, ['tanggal' => $tgl, 'alasan' => $alasan]);

        $peringatan = $tgl <= self::hariIni() ? $this->tutupAkun($k, $by) : [];

        return $this->detail($k->user_id, true) + ['peringatan' => $peringatan];
    }

    /** Undo a recorded exit while the login is still active. */
    public function batalKeluar(string $userId, string $by): array
    {
        $k = $this->find($userId);
        $u = $this->users->userById($k->user_id);
        if (! $u || (int) ($u['active'] ?? 0) !== 1) {
            throw new HrisError('sudah_nonaktif', 'User sudah nonaktif; aktifkan lagi lewat Kelola Akses dulu.', 409);
        }
        $k->fill(['tanggal_keluar' => null, 'alasan_keluar' => null, 'catatan_keluar' => null])->save();
        $this->audit->record($by, 'karyawan_batal_keluar', 'hris_karyawan', $k->id, []);

        return $this->detail($k->user_id, true);
    }

    /** hris:harian — exits whose date has come: deactivate and clear the roster. */
    public function prosesKeluar(string $hariIni, string $by): int
    {
        $n = 0;
        foreach (HrisKaryawan::query()->whereNotNull('tanggal_keluar')->where('tanggal_keluar', '<=', $hariIni)->get() as $k) {
            $u = $this->users->userById($k->user_id);
            if ($u && (int) ($u['active'] ?? 0) === 1) {
                $this->tutupAkun($k, $by);
                $n++;
            }
        }

        return $n;
    }

    /** E4 "move every member to…": one Divisi change (with history) per member. */
    public function pindahkanDivisi(string $dariId, string $keId, string $berlaku, string $by): int
    {
        if ($dariId === $keId) {
            throw new HrisError('validation_failed', 'Divisi tujuan sama dengan asal.', 422);
        }
        $n = 0;
        foreach (HrisKaryawan::query()->where('divisi_id', $dariId)->whereNull('tanggal_keluar')->pluck('user_id') as $uid) {
            $this->update((string) $uid, ['divisi_id' => $keId, 'berlaku_dari' => $berlaku,
                'catatan' => 'Dipindah bersama seluruh Divisi'], $by);
            $n++;
        }

        return $n;
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<int,string> warnings */
    private function tutupAkun(HrisKaryawan $k, string $by): array
    {
        $out = [];
        $r = $this->account->setActiveCore('', $k->user_id, false);
        if (! ($r['ok'] ?? false)) {
            $out[] = 'User tidak bisa dinonaktifkan: '.($r['error'] ?? '?');
        }
        try {
            $this->jadwal->hapusSelSesudah($k->user_id, $k->tanggal_keluar->format('Y-m-d'), $by);
        } catch (\Throwable $e) {
            report($e);
            $out[] = 'Sel jadwal sesudah tanggal keluar belum terhapus.';
        }
        if ($out !== []) {
            $this->audit->record($by, 'karyawan_keluar_sebagian', 'hris_karyawan', $k->id, ['peringatan' => $out]);
        }

        return $out;
    }

    /** Legacy views after a write (E3, E5); failures become warnings. */
    private function setelahTulis(HrisKaryawan $k, string $by, bool $tempatkan): array
    {
        $k->loadMissing(['divisi', 'jabatan.level']);
        $peringatan = [];
        if ($tempatkan) {
            try {
                $this->jadwal->tempatkan($k->user_id,
                    $k->divisi && $k->divisi->jenis === 'shift' ? $k->divisi->kode : null, $by);
            } catch (\Throwable $e) {
                report($e);
                $peringatan[] = 'Penempatan Divisi di Jadwal belum diperbarui.';
            }
        }
        try {
            $this->cerminkanKeUser($k);
        } catch (\Throwable $e) {
            report($e);
            $peringatan[] = 'Data HR di akun Office belum diperbarui.';
        }
        if ($peringatan !== []) {
            $this->audit->record($by, 'sinkron_gagal', 'hris_karyawan', $k->id, ['peringatan' => $peringatan]);
        }
        $this->userMap = null;

        return $this->detail($k->user_id, true) + ['peringatan' => $peringatan];
    }

    /** E3: Jabatan, status kerja, join date and Divisi name on the Office User. */
    private function cerminkanKeUser(HrisKaryawan $k): void
    {
        $u = $this->users->userById($k->user_id);
        if (! $u) {
            return;
        }
        $hr = $this->kolomHr(['jabatan_id' => $k->jabatan_id, 'status_kerja' => $k->status_kerja,
            'tanggal_gabung' => $k->tanggal_gabung?->format('Y-m-d'), 'divisi_id' => $k->divisi_id]);
        $sama = true;
        foreach (['jobPosition' => 'job_position', 'employmentStatus' => 'employment_status',
            'joinDate' => 'join_date', 'organization' => 'organization'] as $key => $col) {
            if (array_key_exists($key, $hr) && (string) ($u[$col] ?? '') !== $hr[$key]) {
                $sama = false;
            }
        }
        if ($sama) {
            return;
        }
        $r = $this->account->rosterSaveUserCore(['id' => $k->user_id, 'name' => (string) $u['name'],
            'keterangan' => (string) ($u['keterangan'] ?? ''), 'noHp' => (string) ($u['no_hp'] ?? ''),
            'talentaId' => (string) ($u['talenta_id'] ?? '')] + $hr);
        if (! ($r['ok'] ?? false)) {
            throw new \RuntimeException('rosterSaveUserCore: '.($r['error'] ?? '?'));
        }
    }

    /** @return array<string,string> AccountService HR keys from Karyawan attributes */
    private function kolomHr(array $isi): array
    {
        $out = [];
        if (array_key_exists('jabatan_id', $isi)) {
            $out['jobPosition'] = $isi['jabatan_id'] ? (string) HrisJabatan::query()->whereKey($isi['jabatan_id'])->value('nama') : '';
        }
        if (array_key_exists('status_kerja', $isi)) {
            $out['employmentStatus'] = self::STATUS_TALENTA[$isi['status_kerja'] ?? ''] ?? '';
        }
        if (array_key_exists('tanggal_gabung', $isi)) {
            $out['joinDate'] = (string) ($isi['tanggal_gabung'] ?? '');
        }
        if (array_key_exists('divisi_id', $isi)) {
            $out['organization'] = $isi['divisi_id'] ? (string) HrisDivisi::query()->whereKey($isi['divisi_id'])->value('nama') : '';
        }

        return $out;
    }

    /**
     * Validated Karyawan attributes from input; only keys present are
     * returned (a PATCH changes what it sends).
     *
     * @return array<string,mixed>
     */
    private function isi(array $in, ?HrisKaryawan $k, ?string $userId = null): array
    {
        $uid = $k?->user_id ?? $userId;
        $out = [];
        $teks = ['alamat' => 500, 'kontak_darurat_nama' => 120, 'kontak_darurat_hp' => 32];
        foreach ($teks as $col => $max) {
            if (array_key_exists($col, $in)) {
                $out[$col] = mb_substr(self::s($in[$col]), 0, $max) ?: null;
            }
        }
        foreach (['tanggal_lahir', 'tanggal_gabung', 'akhir_percobaan', 'akhir_kontrak'] as $col) {
            if (array_key_exists($col, $in)) {
                $v = self::s($in[$col]);
                $t = PunchRules::tglValid($v);
                if ($v !== '' && $t === '') {
                    throw new HrisError('validation_failed', "Tanggal {$col} harus YYYY-MM-DD.", 422);
                }
                $out[$col] = $t ?: null;
            }
        }
        if (array_key_exists('nik', $in)) {
            $nik = preg_replace('/\D+/', '', self::s($in['nik'])) ?? '';
            if ($nik !== '' && strlen($nik) !== 16) {
                throw new HrisError('validation_failed', 'NIK harus 16 angka.', 422);
            }
            if ($nik !== '' && HrisKaryawan::withTrashed()->where('nik', $nik)->when($uid, fn ($q) => $q->where('user_id', '<>', $uid))->exists()) {
                throw new HrisError('validation_failed', 'NIK sudah dipakai karyawan lain.', 422);
            }
            $out['nik'] = $nik ?: null;
        }
        if (array_key_exists('jenis_kelamin', $in)) {
            $jk = strtoupper(self::s($in['jenis_kelamin']));
            if ($jk !== '' && ! in_array($jk, ['L', 'P'], true)) {
                throw new HrisError('validation_failed', 'Jenis kelamin harus L atau P.', 422);
            }
            $out['jenis_kelamin'] = $jk ?: null;
        }
        if (array_key_exists('status_kerja', $in)) {
            $st = strtoupper(self::s($in['status_kerja']));
            if ($st !== '' && ! in_array($st, HrisKaryawan::STATUS_KERJA, true)) {
                throw new HrisError('validation_failed', 'Status kerja harus PROBATION, KONTRAK, TETAP atau MAGANG.', 422);
            }
            $out['status_kerja'] = $st ?: null;
        }
        if (array_key_exists('divisi_id', $in)) {
            $id = self::s($in['divisi_id']);
            if ($id !== '' && ! HrisDivisi::query()->whereKey($id)->where('aktif', true)->exists()) {
                throw new HrisError('validation_failed', 'Divisi tidak dikenal atau nonaktif.', 422);
            }
            $out['divisi_id'] = $id ?: null;
            if ($id !== '') {
                $out['divisi_impor'] = null;
            }
        }
        if (array_key_exists('jabatan_id', $in)) {
            $id = self::s($in['jabatan_id']);
            if ($id !== '' && ! HrisJabatan::query()->whereKey($id)->where('aktif', true)->exists()) {
                throw new HrisError('validation_failed', 'Jabatan tidak dikenal atau nonaktif.', 422);
            }
            $out['jabatan_id'] = $id ?: null;
            if ($id !== '') {
                $out['jabatan_impor'] = null;
            }
        }
        if (array_key_exists('atasan_user_id', $in)) {
            $a = self::s($in['atasan_user_id']);
            if ($a !== '') {
                $this->assertAtasan($uid, $a);
            }
            $out['atasan_user_id'] = $a ?: null;
        }

        return $out;
    }

    /** Superior must be an employed Karyawan, not the person, and not below them. */
    private function assertAtasan(?string $uid, string $atasan): void
    {
        if ($uid !== null && $atasan === $uid) {
            throw new HrisError('validation_failed', 'Atasan tidak boleh diri sendiri.', 422);
        }
        $peta = HrisKaryawan::query()->whereNull('tanggal_keluar')->pluck('atasan_user_id', 'user_id')->all();
        if (! array_key_exists($atasan, $peta)) {
            throw new HrisError('validation_failed', 'Atasan harus karyawan aktif.', 422);
        }
        $lihat = [];
        for ($x = $atasan; $x !== null && ! isset($lihat[$x]); $x = $peta[$x] ?? null) {
            if ($x === $uid) {
                throw new HrisError('validation_failed', 'Atasan ini justru berada di bawah orang tersebut (siklus).', 422);
            }
            $lihat[$x] = true;
        }
    }

    /**
     * @param  array<string,array<int,string>>  $kepala  divisi id => head user ids
     * @return array{0:?string,1:bool} [superior, derived from the Divisi head]
     */
    private function atasanEfektif(HrisKaryawan $k, array $kepala): array
    {
        if ($k->atasan_user_id !== null) {
            return [$k->atasan_user_id, false];
        }
        $heads = $k->divisi_id !== null ? ($kepala[$k->divisi_id] ?? []) : [];
        if (in_array($k->user_id, $heads, true)) {
            return [null, false];
        }

        return [$heads[0] ?? null, ($heads[0] ?? null) !== null];
    }

    /** @return array<string,array<int,string>> divisi id => head user ids */
    private function kepalaPerDivisi(): array
    {
        $out = [];
        foreach ($this->divisi->petaKepala() as $uid => $divs) {
            foreach ($divs as $d) {
                $out[$d][] = (string) $uid;
            }
        }

        return $out;
    }

    private function shape(HrisKaryawan $k, bool $pii, array $kepala): array
    {
        $u = $this->userMap()[$k->user_id] ?? null;
        [$atasan, $otomatis] = $this->atasanEfektif($k, $kepala);
        $tgl = fn ($d) => $d?->format('Y-m-d');
        $out = [
            'user_id' => $k->user_id, 'nama' => (string) ($u['name'] ?? $k->user_id),
            'aktif' => $u !== null && (int) ($u['active'] ?? 0) === 1, 'tim' => (string) ($u['keterangan'] ?? ''),
            'divisi' => $k->divisi ? ['id' => $k->divisi->id, 'kode' => $k->divisi->kode,
                'nama' => $k->divisi->nama, 'jenis' => $k->divisi->jenis] : null,
            'divisi_impor' => $k->divisi_impor,
            'jabatan' => $k->jabatan ? ['id' => $k->jabatan->id, 'nama' => $k->jabatan->nama,
                'level' => $k->jabatan->level?->nama, 'level_urutan' => (int) ($k->jabatan->level?->urutan ?? 0)] : null,
            'jabatan_impor' => $k->jabatan_impor,
            'atasan_user_id' => $k->atasan_user_id,
            'atasan' => $atasan !== null ? ['user_id' => $atasan, 'nama' => $this->nama($atasan), 'otomatis' => $otomatis] : null,
            'status_kerja' => $k->status_kerja,
            'tanggal_gabung' => $tgl($k->tanggal_gabung), 'akhir_percobaan' => $tgl($k->akhir_percobaan),
            'akhir_kontrak' => $tgl($k->akhir_kontrak), 'tanggal_keluar' => $tgl($k->tanggal_keluar),
            'alasan_keluar' => $k->alasan_keluar, 'catatan_keluar' => $k->catatan_keluar,
            'berubah' => (int) $k->version,
        ];
        if ($pii) {
            $out += ['nik' => $k->nik, 'jenis_kelamin' => $k->jenis_kelamin, 'tanggal_lahir' => $tgl($k->tanggal_lahir),
                'alamat' => $k->alamat, 'kontak_darurat_nama' => $k->kontak_darurat_nama,
                'kontak_darurat_hp' => $k->kontak_darurat_hp,
                'no_hp' => (string) ($u['no_hp'] ?? ''), 'email' => (string) ($u['email'] ?? '')];
        }

        return $out;
    }

    private function label(string $kolom, ?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        return match ($kolom) {
            'divisi' => HrisDivisi::withTrashed()->whereKey($v)->value('nama') ?? $v,
            'jabatan' => HrisJabatan::withTrashed()->whereKey($v)->value('nama') ?? $v,
            'atasan' => $this->nama($v),
            default => $v,
        };
    }

    private function nama(string $uid): string
    {
        return (string) ($this->userMap()[$uid]['name'] ?? $uid);
    }

    /** @return array<string,array<string,mixed>> */
    private function userMap(): array
    {
        if ($this->userMap === null) {
            $this->userMap = [];
            foreach ($this->users->allUsers() as $u) {
                $this->userMap[(string) $u['id']] = $u;
            }
        }

        return $this->userMap;
    }

    private static function pesanAkun(array $res): string
    {
        return match ($res['error'] ?? '') {
            'name_taken' => 'Nama sudah dipakai '.($res['takenBy'] ?? 'user lain').'.',
            'email_taken' => 'Email sudah dipakai '.($res['takenBy'] ?? 'user lain').'.',
            'bad_email' => 'Format email tidak valid.',
            'talenta_taken' => 'ID Talenta sudah dipakai.',
            'missing_fields' => 'Nama wajib diisi.',
            default => 'Akun Office tidak bisa dibuat ('.($res['error'] ?? '?').').',
        };
    }
}
