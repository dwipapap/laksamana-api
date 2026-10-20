<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisJabatan;
use App\Modules\Hris\Models\HrisJabatanLevel;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Divisi;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time (idempotent) fill of the HRIS master data (ADR-0012) from what
 * exists today: the HR columns of the Office `users` row, the legacy HR
 * panel's `employees`/`divisions`, and the Jadwal Divisi of each crew member.
 *
 * Never overwrites: a User that already has a Karyawan row is skipped, an
 * existing Divisi or Jabatan is reused by kode/name. Inactive Users are not
 * imported (they left before HRIS). Whatever cannot be placed is reported
 * for HR instead of guessed.
 */
final class HrisImporKaryawanService
{
    /** Legacy HR division id => shift Divisi kode (the rest become kantor Divisi). */
    private const HR_DIVISI_SHIFT = ['d_bar' => 'bar', 'd_kitchen' => 'kitchen', 'd_store' => 'floor'];

    /** Level by Jabatan words, checked in this order; no match = level 2 (Crew). */
    private const LEVEL_KATA = [
        6 => ['manager', 'manajer', 'gm', 'owner', 'ceo', 'direktur', 'director'],
        5 => ['head', 'kepala'],
        4 => ['captain', 'supervisor', 'spv', 'cdp', 'leader'],
        3 => ['senior'],
        1 => ['helper', 'trainee', 'dishwasher', 'steward', 'intern', 'magang'],
    ];

    private const STATUS_KERJA = [
        'contract' => 'KONTRAK', 'kontrak' => 'KONTRAK', 'pkwt' => 'KONTRAK',
        'probation' => 'PROBATION', 'percobaan' => 'PROBATION',
        'intern' => 'MAGANG', 'internship' => 'MAGANG', 'magang' => 'MAGANG',
        'permanent' => 'TETAP', 'tetap' => 'TETAP', 'pkwtt' => 'TETAP',
    ];

    public function __construct(
        private readonly AccountRepository $users,
        private readonly JadwalService $jadwal,
        private readonly HrisAuditService $audit,
        private readonly HrisMasterBawaan $bawaan,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /**
     * @return array{dibuat:int, sudah_ada:int, divisi_dibuat:array<int,string>, jabatan_dibuat:array<int,string>,
     *   tanpa_divisi:array<int,string>, bukan_karyawan:array<int,string>, nonaktif:int}
     */
    public function impor(string $by, bool $coba = false): array
    {
        $lapor = ['dibuat' => 0, 'sudah_ada' => 0, 'divisi_dibuat' => [], 'jabatan_dibuat' => [],
            'tanpa_divisi' => [], 'bukan_karyawan' => [], 'nonaktif' => 0];

        DB::connection('core')->beginTransaction();
        try {
            $this->bawaan->pastikan();
            $hrDivisi = $this->imporDivisiHr($lapor);
            $hrOrang = $this->hrEmployeesByTalenta();
            $jadwalDivisi = $this->jadwalDivisi();
            $sudah = array_flip(HrisKaryawan::withTrashed()->pluck('user_id')->all());

            foreach ($this->users->allUsers() as $u) {
                $uid = self::s($u['id'] ?? '');
                if ((int) ($u['active'] ?? 0) !== 1) {
                    $lapor['nonaktif']++;

                    continue;
                }
                if (isset($sudah[$uid])) {
                    $lapor['sudah_ada']++;

                    continue;
                }
                $label = self::s($u['name'] ?? '').' ('.$uid.')';
                $hr = $hrOrang[self::s($u['talenta_id'] ?? '')] ?? null;
                $org = self::s($u['organization'] ?? '');
                $posisi = self::s($u['job_position'] ?? '');
                $status = self::s($u['employment_status'] ?? '');
                $divJadwal = $jadwalDivisi[$uid] ?? Divisi::NONSHIFT;

                if ($org === '' && $posisi === '' && $status === '' && $hr === null && $divJadwal === Divisi::NONSHIFT) {
                    $lapor['bukan_karyawan'][] = $label;

                    continue;
                }

                $divisiId = $this->divisiDari($org, $hr, $hrDivisi, $divJadwal);
                if ($divisiId === null) {
                    $lapor['tanpa_divisi'][] = $label;
                }

                HrisKaryawan::query()->create([
                    'user_id' => $uid,
                    'divisi_id' => $divisiId,
                    'divisi_impor' => $divisiId === null ? (mb_substr($org !== '' ? $org : self::s($u['keterangan'] ?? ''), 0, 80) ?: null) : null,
                    'jabatan_id' => $posisi === '' ? null : $this->jabatan($posisi, $lapor),
                    'status_kerja' => self::STATUS_KERJA[strtolower($status)] ?? null,
                    'tanggal_gabung' => PunchRules::tglValid($u['join_date'] ?? '') ?: (PunchRules::tglValid($hr->joinDate ?? '') ?: null),
                    'tanggal_lahir' => PunchRules::tglValid($hr->birthDate ?? '') ?: null,
                    'akhir_percobaan' => PunchRules::tglValid($hr->probationEnd ?? '') ?: null,
                    'akhir_kontrak' => PunchRules::tglValid($hr->contractEnd ?? '') ?: null,
                ]);
                $lapor['dibuat']++;
            }

            $this->audit->record($by, 'karyawan_diimpor', 'hris_karyawan', '',
                ['dibuat' => $lapor['dibuat'], 'coba' => $coba]);

            if ($coba) {
                DB::connection('core')->rollBack();
            } else {
                DB::connection('core')->commit();
            }
        } catch (\Throwable $e) {
            DB::connection('core')->rollBack();
            throw $e;
        }

        return $lapor;
    }

    /**
     * Legacy HR divisions: the shift ones map to their Jadwal code, the rest
     * become kantor Divisi (created once, matched by name afterwards).
     *
     * @return array<string,string> legacy division id => hris_divisi id
     */
    private function imporDivisiHr(array &$lapor): array
    {
        $out = [];
        foreach ($this->hrRows('divisions') as $id => $d) {
            $kode = self::HR_DIVISI_SHIFT[$id] ?? null;
            $nama = self::s($d->name ?? '') ?: $id;
            $row = $kode !== null
                ? HrisDivisi::query()->where('kode', $kode)->first()
                : HrisDivisi::withTrashed()->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->first();
            if (! $row) {
                $row = HrisDivisi::query()->create(['kode' => $this->kodeBaru($nama), 'nama' => mb_substr($nama, 0, 60),
                    'jenis' => 'kantor', 'urutan' => 100 + count($lapor['divisi_dibuat']) * 10, 'aktif' => true]);
                $lapor['divisi_dibuat'][] = $nama;
            }
            $out[$id] = (string) $row->id;
        }

        return $out;
    }

    /** A free kode from a name: slug, cut to 32, suffixed on a clash. */
    public function kodeBaru(string $nama): string
    {
        $dasar = mb_substr(Str::slug($nama), 0, 28) ?: 'divisi';
        $kode = $dasar;
        for ($i = 2; HrisDivisi::withTrashed()->where('kode', $kode)->exists(); $i++) {
            $kode = $dasar.'-'.$i;
        }

        return $kode;
    }

    /**
     * Talenta Organization first (it is what HR typed), then the legacy HR
     * division, then the Jadwal Divisi (Penempatan Divisi or Tim words).
     */
    private function divisiDari(string $org, ?object $hr, array $hrDivisi, string $divJadwal): ?string
    {
        $kode = $org === '' ? Divisi::NONSHIFT : Divisi::resolve('', [], ['keterangan' => $org]);
        if ($kode === Divisi::NONSHIFT && $org !== '') {
            $byNama = HrisDivisi::query()->whereRaw('LOWER(nama) = ?', [mb_strtolower($org)])->value('id');
            if ($byNama) {
                return (string) $byNama;
            }
        }
        if ($kode === Divisi::NONSHIFT && $hr !== null && isset($hrDivisi[self::s($hr->divId ?? '')])) {
            return $hrDivisi[self::s($hr->divId)];
        }
        if ($kode === Divisi::NONSHIFT) {
            $kode = $divJadwal;
        }
        if ($kode === Divisi::NONSHIFT) {
            return null;
        }
        $id = HrisDivisi::query()->where('kode', $kode)->value('id');

        return $id ? (string) $id : null;
    }

    /** Jabatan by name (case-insensitive); created with a guessed level. */
    private function jabatan(string $posisi, array &$lapor): string
    {
        $nama = preg_replace('/\s+/', ' ', $posisi) ?? $posisi;
        $nama = mb_substr(ucwords($nama), 0, 120);
        $row = HrisJabatan::withTrashed()->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->first();
        if (! $row) {
            $row = HrisJabatan::query()->create(['nama' => $nama, 'level_id' => $this->levelUntuk($nama), 'aktif' => true]);
            $lapor['jabatan_dibuat'][] = $nama;
        }

        return (string) $row->id;
    }

    public function levelUntuk(string $jabatan): string
    {
        $kata = preg_split('/[^a-z]+/', strtolower($jabatan), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $urutan = 2;
        foreach (self::LEVEL_KATA as $lv => $daftar) {
            if (array_intersect($kata, $daftar) !== []) {
                $urutan = $lv;
                break;
            }
        }
        $id = HrisJabatanLevel::query()->where('urutan', $urutan)->value('id')
            ?? HrisJabatanLevel::query()->orderBy('urutan')->value('id');
        if (! $id) {
            throw new HrisError('validation_failed', 'Belum ada level jabatan.', 422);
        }

        return (string) $id;
    }

    /** @return array<string,object> talentaId => employee data */
    private function hrEmployeesByTalenta(): array
    {
        $out = [];
        foreach ($this->hrRows('employees') as $d) {
            $t = self::s($d->talentaId ?? '');
            if ($t !== '') {
                $out[$t] = $d;
            }
        }

        return $out;
    }

    /** @return array<string,object> id => decoded `data` of a legacy HR table; [] when unreadable */
    private function hrRows(string $table): array
    {
        try {
            $rows = Modules::db('hr')->table($table)->select(['id', 'data'])->get();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->data);
            $out[(string) $r->id] = is_object($d) ? $d : (object) [];
        }

        return $out;
    }

    /** @return array<string,string> userId => Jadwal Divisi; [] when Jadwal is unreadable */
    private function jadwalDivisi(): array
    {
        try {
            $out = [];
            foreach ($this->jadwal->rosterWithDivisi() as $r) {
                $r = (array) $r;
                $out[self::s($r['id'] ?? '')] = self::s($r['divisi'] ?? '') ?: Divisi::NONSHIFT;
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
