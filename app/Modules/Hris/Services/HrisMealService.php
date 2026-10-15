<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Models\HrisPeriode;
use App\Modules\Hris\Models\HrisRekapUangMakan;
use App\Modules\Hris\Models\HrisTarifUangMakan;
use App\Modules\Jadwal\Services\JadwalService;

/**
 * Meal allowance recap (P1–P2): the variable pay Part HRIS owns. A day
 * counts when it has a MASUK VALID — late still counts, a worked holiday
 * counts (it also gives PH), MENUNGGU counts only once decided, and a
 * period locks only when its queue is empty. Fixed salary is NOT stored
 * here (P3 is a plan, not a build). Pekerja Harian are excluded by
 * construction: they are Pihak, never Users, so they never have punches.
 */
final class HrisMealService
{
    public function __construct(
        private readonly JadwalService $jadwal,
        private readonly AccountRepository $users,
        private readonly HrisAuditService $audit,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    public static function bulanValid(mixed $v): string
    {
        $v = trim((string) $v);
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $v, $m)) {
            return '';
        }

        return checkdate((int) $m[2], 1, (int) $m[1]) ? $v : '';
    }

    // ---------------------------------------------------------------- rates

    /** @return array<int,array{id:string,divisi:string,grade:string,nominal:int,berlaku_dari:string}> */
    public function tarifs(): array
    {
        $out = [];
        foreach (HrisTarifUangMakan::query()->orderBy('divisi')->orderBy('grade')->orderBy('berlaku_dari')->get() as $r) {
            $out[] = ['id' => $r->id, 'divisi' => $r->divisi, 'grade' => $r->grade,
                'nominal' => (int) $r->nominal, 'berlaku_dari' => $r->berlaku_dari->format('Y-m-d'),
                'berubah' => (int) $r->version];
        }

        return $out;
    }

    public function saveTarif(array $in, string $by): HrisTarifUangMakan
    {
        $divisi = self::s($in['divisi'] ?? '*');
        $grade = mb_substr(self::s($in['grade'] ?? ''), 0, 64);
        $nominal = $in['nominal'] ?? null;
        if (! is_numeric($nominal) || (int) $nominal < 0 || (int) $nominal > 1000000000) {
            throw new HrisError('validation_failed', 'Nominal harus rupiah ≥ 0.', 422);
        }
        $dari = PunchRules::tglValid($in['berlaku_dari'] ?? '');
        if ($dari === '') {
            throw new HrisError('validation_failed', 'Berlaku-dari harus YYYY-MM-DD.', 422);
        }
        $id = self::s($in['id'] ?? '');
        $row = $id !== '' ? HrisTarifUangMakan::query()->find($id) : null;
        $data = ['divisi' => mb_substr($divisi !== '' ? $divisi : '*', 0, 64),
            'grade' => $grade, 'nominal' => (int) $nominal, 'berlaku_dari' => $dari];
        if ($row) {
            $row->fill($data)->save();
        } else {
            $row = HrisTarifUangMakan::query()->create($data);
        }
        $this->audit->record($by, 'tarif_disimpan', 'hris_tarif_uang_makan', $row->id, $data);

        return $row;
    }

    public function deleteTarif(string $id, string $by): bool
    {
        $row = HrisTarifUangMakan::query()->find(self::s($id));
        if (! $row) {
            return false;
        }
        $row->delete();
        $this->audit->record($by, 'tarif_dihapus', 'hris_tarif_uang_makan', $id, []);

        return true;
    }

    /**
     * Rate for one person on one date: exact (divisi, grade), then (divisi),
     * then (grade), then the global default — latest berlaku_dari at work.
     */
    public function nominalUntuk(string $divisi, string $grade, string $tanggal): int
    {
        foreach ([[$divisi, $grade], [$divisi, ''], ['*', $grade], ['*', '']] as [$d, $g]) {
            $r = HrisTarifUangMakan::query()->where('divisi', $d)->where('grade', $g)
                ->where('berlaku_dari', '<=', $tanggal)->orderByDesc('berlaku_dari')->first();
            if ($r) {
                return (int) $r->nominal;
            }
        }

        return 0;
    }

    // ---------------------------------------------------------------- recap

    /** Distinct hadir dates (MASUK VALID) of one user in one month. */
    public function hadirDates(string $userId, string $bulan): array
    {
        $out = HrisKetukan::query()->where('user_id', $userId)->where('arah', 'MASUK')
            ->where('status', 'VALID')
            ->where('tanggal_kerja', 'like', $bulan.'-%')
            ->distinct()->pluck('tanggal_kerja')->all();
        sort($out);

        return array_values($out);
    }

    /** @return array{user_id:string,divisi:string,grade:string,hari_hadir:int,nominal:int,total:int,rincian:array} */
    public function barisUntuk(string $userId, string $bulan): array
    {
        $divisi = $this->divisiOf($userId);
        $grade = $this->gradeOf($userId);
        $dates = $this->hadirDates($userId, $bulan);
        $nominal = $this->nominalUntuk($divisi, $grade, $bulan.'-28');
        $hari = count($dates);

        return ['user_id' => $userId, 'nama' => $this->namaOf($userId), 'divisi' => $divisi, 'grade' => $grade,
            'hari_hadir' => $hari, 'nominal' => $nominal, 'total' => $hari * $nominal,
            'rincian' => $dates];
    }

    /** Live preview (nothing stored). */
    public function pratinjau(string $bulan): array
    {
        $bulan = self::bulanValid($bulan);
        if ($bulan === '') {
            throw new HrisError('validation_failed', 'Bulan harus YYYY-MM.', 422);
        }
        $rows = [];
        $total = 0;
        foreach ($this->userIdsInMonth($bulan) as $uid) {
            $b = $this->barisUntuk($uid, $bulan);
            $rows[] = $b;
            $total += $b['total'];
        }

        return ['bulan' => $bulan, 'terkunci' => $this->terkunci($bulan), 'baris' => $rows,
            'total' => $total, 'antrean' => $this->antreanDalamBulan($bulan)];
    }

    /**
     * Lock the month: refused while its queue is non-empty (P2) or already
     * locked, and only once the month is over (later punches would fall
     * outside the snapshot). The snapshot rows are written once and never
     * updated, all in one transaction: a failure half-way leaves no rows,
     * and two HR staff locking together cannot both write the snapshot.
     */
    public function kunci(string $bulan, string $by): array
    {
        $bulan = self::bulanValid($bulan);
        if ($bulan === '') {
            throw new HrisError('validation_failed', 'Bulan harus YYYY-MM.', 422);
        }
        if ($bulan >= substr(PunchRules::tglWib(PunchRules::ms()), 0, 7)) {
            throw new HrisError('bulan_belum_selesai', 'Periode baru bisa dikunci setelah bulannya selesai.', 422);
        }

        $n = HrisPeriode::query()->getConnection()->transaction(function () use ($bulan, $by): int {
            HrisPeriode::query()->firstOrCreate(['bulan' => $bulan], ['status' => 'TERBUKA']);
            $periode = HrisPeriode::query()->where('bulan', $bulan)->lockForUpdate()->firstOrFail();
            if ($periode->status === 'TERKUNCI') {
                throw new HrisError('sudah_terkunci', 'Periode ini sudah dikunci.', 409);
            }
            $antrean = $this->antreanDalamBulan($bulan);
            if ($antrean > 0) {
                throw new HrisError('antrean_belum_kosong',
                    "Masih ada {$antrean} ketukan menunggu di bulan ini. Putuskan dulu sebelum mengunci.", 422,
                    ['antrean' => $antrean]);
            }
            $n = 0;
            foreach ($this->userIdsInMonth($bulan) as $uid) {
                $b = $this->barisUntuk($uid, $bulan);
                HrisRekapUangMakan::query()->create([
                    'periode_id' => $periode->id, 'user_id' => $uid, 'divisi' => $b['divisi'],
                    'grade' => $b['grade'], 'hari_hadir' => $b['hari_hadir'], 'nominal' => $b['nominal'],
                    'total' => $b['total'], 'rincian' => $b['rincian'],
                ]);
                $n++;
            }
            $periode->fill(['status' => 'TERKUNCI', 'dikunci_oleh' => mb_substr($by, 0, 120),
                'dikunci_at' => PunchRules::ms()])->save();

            return $n;
        });
        $this->audit->record($by, 'periode_dikunci', 'hris_periode', $bulan, ['baris' => $n]);

        return $this->rekap($bulan);
    }

    /** Locked snapshot rows (or live preview rows when still open). */
    public function rekap(string $bulan): array
    {
        $bulan = self::bulanValid($bulan);
        if ($bulan === '') {
            throw new HrisError('validation_failed', 'Bulan harus YYYY-MM.', 422);
        }
        $periode = HrisPeriode::query()->where('bulan', $bulan)->first();
        if ($periode && $periode->status === 'TERKUNCI') {
            $rows = [];
            $total = 0;
            foreach (HrisRekapUangMakan::query()->where('periode_id', $periode->id)->orderBy('user_id')->get() as $r) {
                $rows[] = ['user_id' => $r->user_id, 'nama' => $this->namaOf($r->user_id),
                    'divisi' => $r->divisi, 'grade' => $r->grade,
                    'hari_hadir' => (int) $r->hari_hadir, 'nominal' => (int) $r->nominal,
                    'total' => (int) $r->total, 'rincian' => $r->rincian ?? []];
                $total += (int) $r->total;
            }

            return ['bulan' => $bulan, 'terkunci' => true,
                'dikunci_oleh' => $periode->dikunci_oleh, 'dikunci_at' => (int) $periode->dikunci_at,
                'baris' => $rows, 'total' => $total];
        }
        $p = $this->pratinjau($bulan);
        $p['dikunci_oleh'] = '';
        $p['dikunci_at'] = 0;

        return $p;
    }

    /** CSV export (semicolon-safe commas; XLSX follows when PhpSpreadsheet is added). */
    public function eksporCsv(string $bulan): array
    {
        $r = $this->rekap($bulan);
        $lines = ['user_id,divisi,grade,hari_hadir,nominal,total'];
        foreach ($r['baris'] as $b) {
            $lines[] = implode(',', [$b['user_id'], $b['divisi'], $b['grade'],
                $b['hari_hadir'], $b['nominal'], $b['total']]);
        }

        return ['nama' => 'rekap-uang-makan-'.$bulan.($r['terkunci'] ? '' : '-draft').'.csv',
            'isi' => implode("\n", $lines)."\n"];
    }

    // ---------------------------------------------------------------- helpers

    public function terkunci(string $bulan): bool
    {
        $p = HrisPeriode::query()->where('bulan', $bulan)->first();

        return $p !== null && $p->status === 'TERKUNCI';
    }

    public function antreanDalamBulan(string $bulan): int
    {
        return (int) HrisKetukan::query()->where('status', 'MENUNGGU')
            ->where('tanggal_kerja', 'like', $bulan.'-%')->count();
    }

    /** @return array<int,string> */
    private function userIdsInMonth(string $bulan): array
    {
        $out = HrisKetukan::query()->where('arah', 'MASUK')->where('status', 'VALID')
            ->where('tanggal_kerja', 'like', $bulan.'-%')
            ->distinct()->pluck('user_id')->all();
        sort($out);

        return array_values($out);
    }

    private function divisiOf(string $userId): string
    {
        try {
            return $this->jadwal->divisiUser($userId);
        } catch (\Throwable) {
            return 'nonshift';
        }
    }

    private function gradeOf(string $userId): string
    {
        try {
            $u = $this->users->userById($userId);
        } catch (\Throwable) {
            return '';
        }

        return mb_substr(trim((string) ($u['job_level'] ?? $u['jobLevel'] ?? '')), 0, 64);
    }

    private function namaOf(string $userId): string
    {
        try {
            $u = $this->users->userById($userId);

            return $u ? trim((string) ($u['name'] ?? $userId)) : $userId;
        } catch (\Throwable) {
            return $userId;
        }
    }
}
