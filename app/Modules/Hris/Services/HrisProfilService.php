<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Account\Services\AccountService;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Models\HrisPerubahanProfil;

/**
 * Profile change requests (issue #344): crew propose contact changes, HR
 * module admins approve or reject. The five requestable fields are fixed:
 * email + no_hp live on the Office User (Account), alamat + emergency
 * contact on the HRIS Karyawan row (core). Nothing official changes at
 * submit; approval compares the requested fields against the server snapshot
 * and applies each target exactly once.
 *
 * Storage spans two connections: `core` (the request + Karyawan) and the
 * legacy account database (email/no_hp). They share no transaction, so an
 * approval decision is persisted as DIPROSES first; the Account write, then
 * the Karyawan write, each mark their own durable flag only after the value
 * is verified on read-back. A crash leaves a DIPROSES row with a visible
 * error that HR retries through the same decision endpoint.
 */
final class HrisProfilService
{
    /** The only fields a crew request may carry (owner decision 22 Okt 2026). */
    public const FIELDS = ['email', 'no_hp', 'alamat', 'kontak_darurat_nama', 'kontak_darurat_hp'];

    /** Fields stored on the Office User. The rest live on HrisKaryawan. */
    private const AKUN = ['email', 'no_hp'];

    private const HRIS = ['alamat', 'kontak_darurat_nama', 'kontak_darurat_hp'];

    /** Request rows that still block a new submit. */
    private const AKTIF = [HrisPerubahanProfil::MENUNGGU, HrisPerubahanProfil::DIPROSES];

    /**
     * Test seam for failure injection: when set, the writer for that target
     * calls it instead of touching storage (throw to simulate a failure).
     *
     * @var callable(string,HrisPerubahanProfil):void|null
     */
    public static $ujiGagalTulis = null;

    public function __construct(
        private readonly OfficeAccess $access,
        private readonly AccountRepository $users,
        private readonly HrisAuditService $audit,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    private static function hariIni(): string
    {
        return PunchRules::tglWib(PunchRules::ms());
    }

    // ------------------------------------------------------------------ read

    /** Current contact truth for the token holder, from both storages. */
    public function profil(array $me): array
    {
        $uid = self::s($me['id'] ?? '');
        [$u, $k] = $this->karyawanAktif($uid);
        $kontak = $this->kontakSekarang($u, $k);

        return [
            'user_id' => $uid,
            'nama' => (string) ($u['name'] ?? $uid),
            'aktif' => true,
            'tim' => (string) ($u['keterangan'] ?? ''),
            'divisi' => $k->divisi ? ['id' => $k->divisi->id, 'kode' => $k->divisi->kode, 'nama' => $k->divisi->nama] : null,
            'jabatan' => $k->jabatan ? ['id' => $k->jabatan->id, 'nama' => $k->jabatan->nama] : null,
            'status_kerja' => $k->status_kerja,
            'kontak' => $kontak,
            'editable_fields' => self::FIELDS,
            'revisi_profil' => $this->revisi($k, $kontak),
            'pengajuan_aktif' => ($a = $this->aktifUntuk($uid)) ? $this->ringkas($a) : null,
        ];
    }

    /**
     * Own history, newest first, paginated.
     *
     * @return array{data:array<int,array<string,mixed>>,meta:array{total:int,page:int,per_page:int}}
     */
    public function riwayatSaya(array $me, ?string $status, int $page, int $perPage): array
    {
        $uid = self::s($me['id'] ?? '');
        $this->karyawanAktif($uid);
        $q = HrisPerubahanProfil::query()->where('user_id', $uid)->orderByDesc('created_at')->orderByDesc('id');
        $status = strtoupper(self::s($status ?? ''));
        if ($status !== '') {
            $this->assertStatus($status);
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $rows = $q->skip(max(0, ($page - 1) * $perPage))->take($perPage)->get();
        $kini = $this->kontakSekarang($uid);

        return [
            'data' => array_map(fn ($r) => $this->detail($r, $kini), $rows->all()),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ];
    }

    /** Own request detail; another person's id is a 404, never a 403. */
    public function detailSaya(array $me, string $id): array
    {
        $uid = self::s($me['id'] ?? '');
        $this->karyawanAktif($uid);
        $row = HrisPerubahanProfil::query()->find(self::s($id))
            ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
        if ($row->user_id !== $uid) {
            throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
        }

        return $this->detail($row, $this->kontakSekarang($uid));
    }

    // ----------------------------------------------------------------- submit

    /**
     * @param  array{perubahan?:mixed,revisi_profil?:mixed,idempotency_key?:mixed}  $in
     */
    public function ajukan(array $me, array $in): array
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        [$u, $k] = $this->karyawanAktif($uid);
        $kini = $this->kontakSekarang($u, $k);

        $mentah = $in['perubahan'] ?? null;
        if (! is_array($mentah) || $mentah === []) {
            throw new HrisError('validation_failed', 'perubahan wajib berisi field yang diubah.', 422);
        }
        $patch = $this->normalisasi($mentah);
        $diff = [];
        foreach ($patch as $f => $v) {
            if ($v !== ($kini[$f] ?? null)) {
                $diff[$f] = $v;
            }
        }
        if ($diff === []) {
            throw new HrisError('tidak_ada_perubahan', 'Tidak ada perubahan: nilai sama dengan data saat ini.', 422);
        }

        $token = self::s($in['revisi_profil'] ?? '');
        if ($token === '') {
            throw new HrisError('validation_failed', 'revisi_profil wajib dikirim bersama pengajuan.', 422);
        }
        if ($token !== $this->revisi($k, $kini)) {
            throw new HrisError('profil_berubah', 'Data profil sudah berubah. Muat ulang lalu ajukan lagi.', 409,
                ['terkini' => $kini]);
        }

        $kunci = self::s($in['idempotency_key'] ?? '');
        if ($kunci === '' || strlen($kunci) > 64) {
            throw new HrisError('validation_failed', 'idempotency_key wajib (maks 64 karakter).', 422);
        }
        if (isset($diff['email']) && $diff['email'] !== ''
            && $this->users->emailOwner($diff['email'], $uid)) {
            throw new HrisError('email_taken', 'Email sudah dipakai akun lain.', 422);
        }

        $dibuat = true;
        $row = HrisPerubahanProfil::query()->getConnection()->transaction(function () use ($uid, $nama, $kini, $diff, $token, $kunci, &$dibuat) {
            $sama = HrisPerubahanProfil::query()->where('user_id', $uid)
                ->where('idempotency_key', $kunci)->lockForUpdate()->first();
            if ($sama) {
                if (! in_array($sama->status, self::AKTIF, true) || $sama->perubahan !== $diff) {
                    throw new HrisError('idempotency_bentrok',
                        'Kunci ini sudah dipakai pengajuan lain. Pakai kunci baru.', 409);
                }
                $dibuat = false;

                return $sama;
            }
            $aktif = HrisPerubahanProfil::query()->where('user_id', $uid)
                ->whereIn('status', self::AKTIF)->lockForUpdate()->first();
            if ($aktif) {
                throw new HrisError('sudah_menunggu', 'Masih ada pengajuan aktif. Batalkan dulu sebelum mengajukan lagi.', 409,
                    ['id' => $aktif->id]);
            }
            $awal = [];
            foreach ($diff as $f => $v) {
                $awal[$f] = $kini[$f] ?? null;
            }

            return HrisPerubahanProfil::query()->create([
                'user_id' => $uid, 'user_ulid' => $this->users->userUlid($uid),
                'status' => HrisPerubahanProfil::MENUNGGU,
                'nilai_awal' => $awal, 'perubahan' => $diff,
                'diajukan_oleh' => mb_substr($nama, 0, 120), 'diajukan_at' => PunchRules::ms(),
                'idempotency_key' => $kunci, 'revisi_profil' => mb_substr($token, 0, 128),
            ]);
        });
        $this->audit->record($nama, 'profil_diajukan', 'hris_perubahan_profil', $row->id,
            ['status' => HrisPerubahanProfil::MENUNGGU, 'field' => array_keys($diff)]);

        return $this->detail($row->fresh(), $this->kontakSekarang($uid)) + ['dibuat' => $dibuat];
    }

    /** Cancel an own request while it still waits for HR. */
    public function batal(array $me, string $id, array $in): array
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        $this->karyawanAktif($uid);
        $row = HrisPerubahanProfil::query()->getConnection()->transaction(function () use ($uid, $nama, $id, $in) {
            $r = HrisPerubahanProfil::query()->where('user_id', $uid)
                ->lockForUpdate()->find(self::s($id))
                ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
            HrisDivisiService::assertVersion($r->version, $in);
            if ($r->status !== HrisPerubahanProfil::MENUNGGU) {
                throw new HrisError('sudah_final', 'Pengajuan ini sudah final dan tidak bisa dibatalkan.', 422);
            }
            $r->fill(['status' => HrisPerubahanProfil::DIBATALKAN,
                'diputus_oleh_id' => $uid, 'diputus_oleh' => mb_substr($nama, 0, 120),
                'diputus_at' => PunchRules::ms()])->save();

            return $r;
        });
        $this->audit->record($nama, 'profil_dibatalkan', 'hris_perubahan_profil', $row->id,
            ['status' => HrisPerubahanProfil::DIBATALKAN]);

        return $this->detail($row->fresh(), $this->kontakSekarang($uid));
    }

    // ------------------------------------------------------------------ HR

    private function assertApprover(array $me): string
    {
        $uid = self::s($me['id'] ?? '');
        if ($uid === '' || ! $this->access->isModuleAdmin($uid, 'hris')) {
            throw new HrisError('forbidden', 'Antrean perubahan profil hanya untuk Admin Modul HRIS.', 403);
        }

        return $uid;
    }

    /**
     * HR queue: oldest first, accurate total, optional filters.
     *
     * @return array{data:array<int,array<string,mixed>>,meta:array{total:int,page:int,per_page:int}}
     */
    public function antrean(array $me, ?string $status, ?string $divisi, ?string $q, int $page, int $perPage, ?string $userId = null): array
    {
        $this->assertApprover($me);
        $status = strtoupper(self::s($status ?? ''));
        if ($status === '') {
            $status = HrisPerubahanProfil::MENUNGGU;
        }
        $this->assertStatus($status);
        $userId = self::s($userId ?? '');
        $rows = HrisPerubahanProfil::query()->where('status', $status)
            ->when($userId !== '', fn ($w) => $w->where('user_id', $userId))
            ->orderBy('created_at')->orderBy('id')->get();
        $divisi = self::s($divisi ?? '');
        $cari = mb_strtolower(self::s($q ?? ''));
        $karyawan = HrisKaryawan::query()->with(['divisi', 'jabatan'])
            ->whereIn('user_id', $rows->pluck('user_id')->unique()->all())->get()->keyBy('user_id');
        $nama = $this->users->namesByIds($rows->pluck('user_id')->unique()->all());
        $out = [];
        foreach ($rows as $r) {
            /** @var HrisKaryawan|null $k */
            $k = $karyawan[$r->user_id] ?? null;
            if ($divisi !== '' && ($k?->divisi_id) !== $divisi) {
                continue;
            }
            $nm = (string) ($nama[$r->user_id] ?? $r->user_id);
            if ($cari !== '' && ! str_contains(mb_strtolower($nm.' '.$r->user_id), $cari)) {
                continue;
            }
            $out[] = $this->ringkas($r, $nm, $k?->divisi?->nama);
        }
        $total = count($out);
        $out = array_slice($out, max(0, ($page - 1) * $perPage), $perPage);

        return ['data' => $out, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage]];
    }

    /** HR detail with live conflict flags per requested field. */
    public function detailKelola(array $me, string $id): array
    {
        $this->assertApprover($me);
        $row = HrisPerubahanProfil::query()->find(self::s($id))
            ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);

        return $this->detail($row, $this->kontakSekarang($row->user_id), true);
    }

    /**
     * @param  array{status?:mixed,catatan?:mixed,berubah?:mixed,version?:mixed,idempotency_key?:mixed}  $in
     */
    public function putuskan(array $me, string $id, array $in): array
    {
        $uid = $this->assertApprover($me);
        $nama = self::s($me['name'] ?? '');
        $status = strtoupper(self::s($in['status'] ?? ''));
        if ($status !== HrisPerubahanProfil::DISETUJUI && $status !== HrisPerubahanProfil::DITOLAK) {
            throw new HrisError('validation_failed', 'Keputusan hanya boleh DISETUJUI atau DITOLAK.', 422);
        }
        $catatan = mb_substr(self::s($in['catatan'] ?? ''), 0, 500);
        if ($status === HrisPerubahanProfil::DITOLAK && $catatan === '') {
            throw new HrisError('validation_failed', 'Penolakan wajib disertai catatan untuk kru.', 422);
        }

        $row = HrisPerubahanProfil::query()->getConnection()->transaction(function () use ($uid, $id, $in, $status, $nama, $catatan) {
            $r = HrisPerubahanProfil::query()->lockForUpdate()->find(self::s($id))
                ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
            HrisDivisiService::assertVersion($r->version, $in);
            if (in_array($r->status, [HrisPerubahanProfil::DISETUJUI, HrisPerubahanProfil::DITOLAK,
                HrisPerubahanProfil::DIBATALKAN], true)) {
                if ($r->status === $status) {
                    return $r;
                }
                throw new HrisError('already_decided', 'Pengajuan ini sudah diputus.', 409);
            }
            if ($r->status === HrisPerubahanProfil::DIPROSES && $status === HrisPerubahanProfil::DITOLAK) {
                throw new HrisError('sudah_diproses',
                    'Pengajuan ini sudah diproses; hanya bisa dicoba terapkan ulang (DISETUJUI).', 409);
            }
            if ($status === HrisPerubahanProfil::DISETUJUI) {
                // Pre-apply gate inside the lock: a failed check leaves the
                // row untouched (still MENUNGGU/DIPROSES), only the writes
                // move it to DIPROSES first.
                $this->assertSiapTerap($r, $this->kontakSekarang($r->user_id));
            }
            $r->fill(['status' => $status === HrisPerubahanProfil::DITOLAK
                    ? HrisPerubahanProfil::DITOLAK : HrisPerubahanProfil::DIPROSES,
                'diputus_oleh_id' => $uid, 'diputus_oleh' => mb_substr($nama, 0, 120),
                'diputus_at' => PunchRules::ms(), 'catatan' => $catatan === '' ? null : $catatan,
                'apply_percobaan' => ((int) $r->apply_percobaan) + 1])->save();

            return $r;
        });

        if ($status === HrisPerubahanProfil::DITOLAK) {
            $this->audit->record($nama, 'profil_ditolak', 'hris_perubahan_profil', $row->id,
                ['status' => HrisPerubahanProfil::DITOLAK, 'field' => array_keys($row->perubahan ?? [])]);

            return $this->detail($row->fresh(), $this->kontakSekarang($row->user_id), true);
        }

        return $this->terapkan($nama, $row->fresh());
    }

    // ----------------------------------------------------------------- apply

    /**
     * Finish an approved request: Account targets first, then the Karyawan
     * row, then the final status. Every flag is set only after read-back
     * verification; any failure keeps the DIPROSES row with a visible error
     * for an HR retry through putuskan().
     */
    private function terapkan(string $nama, HrisPerubahanProfil $row): array
    {
        try {
            [$u, $k] = $this->karyawanAktif($row->user_id, utamakanPesan: true);
            $this->assertSiapTerap($row, $this->kontakSekarang($u, $k));
            $this->tulisAkun($row);
            $this->tulisHris($row);
        } catch (HrisError $e) {
            $row->fill(['apply_galat' => mb_substr($e->getMessage(), 0, 500)])->save();
            $this->audit->record($nama, 'profil_gagal_terap', 'hris_perubahan_profil', $row->id,
                ['status' => HrisPerubahanProfil::DIPROSES, 'galat' => $e->errorCode]);
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $row->fill(['apply_galat' => 'Penyimpanan gagal di tengah jalan. Buka lagi untuk coba lagi.'])->save();
            $this->audit->record($nama, 'profil_gagal_terap', 'hris_perubahan_profil', $row->id,
                ['status' => HrisPerubahanProfil::DIPROSES, 'galat' => 'terap_gagal']);
            throw new HrisError('terap_gagal',
                'Perubahan disetujui tetapi gagal tersimpan. Buka lagi untuk coba lagi.', 500);
        }
        $row->fill(['status' => HrisPerubahanProfil::DISETUJUI,
            'apply_account_ok' => true, 'apply_core_ok' => true, 'apply_galat' => null])->save();
        $this->audit->record($nama, 'profil_disetujui', 'hris_perubahan_profil', $row->id,
            ['status' => HrisPerubahanProfil::DISETUJUI, 'field' => array_keys($row->perubahan ?? [])]);
        $this->access->forgetUser($row->user_id);

        return $this->detail($row->fresh(), $this->kontakSekarang($row->user_id), true);
    }

    /** Active-employee and uniqueness gate, run at submit and at approval. */
    private function assertSiapTerap(HrisPerubahanProfil $row, array $kini): void
    {
        $this->karyawanAktif($row->user_id, utamakanPesan: true);
        $patch = $row->perubahan ?? [];
        if (isset($patch['email']) && $patch['email'] !== ''
            && ($du = $this->users->emailOwner($patch['email'], $row->user_id))) {
            throw new HrisError('email_taken', 'Email sudah dipakai '.self::s($du['name'] ?? 'user lain').'.', 422);
        }
        $konflik = [];
        foreach ($patch as $f => $baru) {
            $lama = ($row->nilai_awal ?? [])[$f] ?? null;
            $sekarang = $kini[$f] ?? null;
            // Already applied by this request (a retried DIPROSES): not a conflict.
            if ($sekarang !== $lama && $sekarang !== $baru) {
                $konflik[] = $f;
            }
        }
        if ($konflik !== []) {
            throw new HrisError('kontak_berubah',
                'Kontak sudah berubah dari sumber lain. Tinjau nilai terkini sebelum memutus.', 409,
                ['konflik' => $konflik]);
        }
    }

    /** Write the requested Account fields, then verify on read-back. */
    protected function tulisAkun(HrisPerubahanProfil $row): void
    {
        if (self::$ujiGagalTulis) {
            (self::$ujiGagalTulis)('akun', $row);

            return;
        }
        if ($row->apply_account_ok) {
            return;
        }
        $patch = $row->perubahan ?? [];
        $kolom = [];
        foreach (self::AKUN as $f) {
            if (array_key_exists($f, $patch)) {
                $kolom[$f === 'email' ? 'email' : 'no_hp'] = $patch[$f];
            }
        }
        if ($kolom === []) {
            $row->fill(['apply_account_ok' => true])->save();

            return;
        }
        $this->users->updateUser($row->user_id, $kolom);
        $this->access->forgetUser($row->user_id);
        $segar = $this->users->userById($row->user_id) ?? [];
        $email = mb_strtolower(self::s($segar['email'] ?? ''), 'UTF-8');
        $hp = self::s($segar['no_hp'] ?? '');
        if (array_key_exists('email', $kolom) && $email !== mb_strtolower((string) $kolom['email'], 'UTF-8')) {
            throw new HrisError('email_tak_tersimpan',
                'Email tidak tersimpan di akun (kolom email belum tersedia). Minta admin menyiapkan kolomnya lalu coba lagi.', 422);
        }
        if (array_key_exists('no_hp', $kolom) && $hp !== (string) $kolom['no_hp']) {
            throw new HrisError('terap_gagal', 'No. HP tidak tersimpan di akun. Coba lagi.', 422);
        }
        $row->fill(['apply_account_ok' => true])->save();
    }

    /** Write the requested Karyawan fields, then verify on read-back. */
    protected function tulisHris(HrisPerubahanProfil $row): void
    {
        if ($row->apply_core_ok) {
            return;
        }
        $patch = $row->perubahan ?? [];
        $isi = [];
        foreach (self::HRIS as $f) {
            if (array_key_exists($f, $patch)) {
                $isi[$f] = $patch[$f];
            }
        }
        if ($isi === []) {
            $row->fill(['apply_core_ok' => true])->save();

            return;
        }
        $k = HrisKaryawan::query()->where('user_id', $row->user_id)->first()
            ?? throw new HrisError('karyawan_nonaktif', 'Karyawan sudah tidak aktif; penerapan dibatalkan.', 422);
        $k->fill($isi)->save();
        $segar = $k->fresh();
        foreach ($isi as $f => $v) {
            if (($segar->{$f} ?? null) !== $v && ! ($segar->{$f} === null && $v === null)) {
                throw new HrisError('terap_gagal', 'Data HRIS tidak tersimpan. Coba lagi.', 422);
            }
        }
        $row->fill(['apply_core_ok' => true])->save();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The employee must exist on both sides and still be employed. Returns
     * the [User row, Karyawan] pair for the caller to shape.
     *
     * @return array{0:array<string,mixed>,1:HrisKaryawan}
     */
    private function karyawanAktif(string $uid, bool $utamakanPesan = false): array
    {
        $u = $this->users->userById($uid);
        if (! $u || (int) ($u['active'] ?? 0) !== 1) {
            throw new HrisError('karyawan_nonaktif',
                $utamakanPesan ? 'User sudah nonaktif; penerapan dibatalkan.' : 'Profil hanya untuk karyawan aktif.', 422);
        }
        $k = HrisKaryawan::query()->with(['divisi', 'jabatan'])->where('user_id', $uid)->first();
        if (! $k || ($k->tanggal_keluar !== null && $k->tanggal_keluar->format('Y-m-d') < self::hariIni())) {
            throw new HrisError('karyawan_nonaktif',
                $utamakanPesan ? 'Karyawan sudah keluar; penerapan dibatalkan.' : 'Profil hanya untuk karyawan aktif.', 422);
        }

        return [$u, $k];
    }

    /**
     * Current values for the five fields, normalized exactly like a submit
     * (so diffing is a strict comparison and `+`/leading zeros survive).
     * Pass a user id string, or the [User row, Karyawan] pair directly.
     *
     * @return array<string,string|null>
     */
    private function kontakSekarang(mixed $u = null, ?HrisKaryawan $k = null): array
    {
        if (is_string($u)) {
            $uid = $u;
            $u = $this->users->userById($uid) ?? [];
            $k = HrisKaryawan::query()->where('user_id', $uid)->first();
        }
        $u = is_array($u) ? $u : [];

        return [
            'email' => mb_strtolower(self::s($u['email'] ?? ''), 'UTF-8'),
            'no_hp' => self::s($u['no_hp'] ?? ''),
            'alamat' => ($v = self::s($k?->alamat ?? '')) === '' ? null : $v,
            'kontak_darurat_nama' => ($v = self::s($k?->kontak_darurat_nama ?? '')) === '' ? null : $v,
            'kontak_darurat_hp' => ($v = self::s($k?->kontak_darurat_hp ?? '')) === '' ? null : $v,
        ];
    }

    private function revisi(HrisKaryawan $k, array $kontak): string
    {
        return ((int) $k->version).'.'.substr(sha1((string) json_encode($kontak)), 0, 24);
    }

    /**
     * Strict allowlist + normalization. Unknown keys are refused; empty
     * account strings stay `''` (the Account storage shape) while empty HR
     * strings become `null` (the Karyawan storage shape).
     *
     * @return array<string,string|null>
     */
    private function normalisasi(array $mentah): array
    {
        foreach (array_keys($mentah) as $f) {
            if (! in_array($f, self::FIELDS, true)) {
                throw new HrisError('validation_failed', "Field '{$f}' tidak bisa diubah lewat fitur ini.", 422);
            }
        }
        $out = [];
        if (array_key_exists('email', $mentah)) {
            $e = mb_strtolower(self::s($mentah['email']), 'UTF-8');
            if ($e !== '' && ! AccountService::emailValid($e)) {
                throw new HrisError('bad_email', 'Format email tidak valid.', 422);
            }
            $out['email'] = mb_substr($e, 0, 190);
        }
        if (array_key_exists('no_hp', $mentah)) {
            $h = self::s($mentah['no_hp']);
            if (strlen($h) > 32) {
                throw new HrisError('bad_no_hp', 'No. HP maksimal 32 karakter.', 422);
            }
            $out['no_hp'] = $h;
        }
        $teks = ['alamat' => 500, 'kontak_darurat_nama' => 120, 'kontak_darurat_hp' => 32];
        foreach ($teks as $f => $max) {
            if (array_key_exists($f, $mentah)) {
                $v = mb_substr(self::s($mentah[$f]), 0, $max);
                $out[$f] = $v === '' ? null : $v;
            }
        }

        return $out;
    }

    private function assertStatus(string $status): void
    {
        if (! in_array($status, [HrisPerubahanProfil::MENUNGGU, HrisPerubahanProfil::DIPROSES,
            HrisPerubahanProfil::DISETUJUI, HrisPerubahanProfil::DITOLAK, HrisPerubahanProfil::DIBATALKAN], true)) {
            throw new HrisError('validation_failed', 'Status tidak dikenal.', 422);
        }
    }

    private function aktifUntuk(string $uid): ?HrisPerubahanProfil
    {
        return HrisPerubahanProfil::query()->where('user_id', $uid)
            ->whereIn('status', self::AKTIF)->orderByDesc('created_at')->first();
    }

    /** Queue row: identity + requested field names, never PII values. */
    private function ringkas(HrisPerubahanProfil $r, ?string $nama = null, ?string $divisi = null): array
    {
        return [
            'id' => $r->id, 'user_id' => $r->user_id,
            'nama' => $nama ?? (string) ($this->users->namesByIds([$r->user_id])[$r->user_id] ?? $r->user_id),
            'divisi' => $divisi, 'status' => $r->status,
            'field' => array_keys($r->perubahan ?? []),
            'diajukan_oleh' => $r->diajukan_oleh, 'diajukan_at' => (int) $r->diajukan_at,
            'diputus_oleh' => $r->diputus_oleh, 'diputus_at' => $r->diputus_at === null ? null : (int) $r->diputus_at,
            'berubah' => (int) $r->version,
        ];
    }

    /**
     * Full detail: per-field old/proposed/live rows so both sides can see a
     * conflict without the snapshot being silently replaced.
     */
    private function detail(HrisPerubahanProfil $r, array $kini, bool $kelola = false): array
    {
        $patch = $r->perubahan ?? [];
        $awal = $r->nilai_awal ?? [];
        $beda = [];
        foreach ($patch as $f => $baru) {
            $lama = $awal[$f] ?? null;
            $live = $kini[$f] ?? null;
            $beda[] = ['field' => $f, 'lama' => $lama, 'baru' => $baru, 'terkini' => $live,
                'konflik' => $live !== $lama && $live !== $baru];
        }
        $out = [
            'id' => $r->id, 'user_id' => $r->user_id,
            'nama' => (string) ($this->users->namesByIds([$r->user_id])[$r->user_id] ?? $r->user_id),
            'status' => $r->status, 'beda' => $beda, 'field' => array_keys($patch),
            'diajukan_oleh' => $r->diajukan_oleh, 'diajukan_at' => (int) $r->diajukan_at,
            'diputus_oleh' => $r->diputus_oleh, 'diputus_at' => $r->diputus_at === null ? null : (int) $r->diputus_at,
            'catatan' => $r->catatan, 'berubah' => (int) $r->version,
        ];
        if ($kelola && $r->status === HrisPerubahanProfil::DIPROSES) {
            $out += ['apply_account_ok' => (bool) $r->apply_account_ok,
                'apply_core_ok' => (bool) $r->apply_core_ok, 'apply_galat' => $r->apply_galat,
                'apply_percobaan' => (int) $r->apply_percobaan];
        }

        return $out;
    }
}
