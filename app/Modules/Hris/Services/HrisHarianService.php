<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Models\HrisPengajuan;
use App\Modules\Hris\Models\HrisSaldoMutasi;
use App\Modules\Jadwal\Services\JadwalService;
use Carbon\Carbon;

/**
 * The daily HRIS jobs (L3–L4, L1 transition), run by `hris:harian` from the
 * scheduler (routes/console.php; the owner installs the cPanel cron):
 *
 *   - beriPhHarian: +1 PH for every MASUK VALID on a calendar holiday
 *     (idempotent per user+date; also backfills punches decided VALID late).
 *   - hanguskanPh: expiry rows for PH batches past kedaluwarsa.
 *   - syncCutiJadwalLama: deduct approved CUTI from the old Jadwal panel.
 */
final class HrisHarianService
{
    public function __construct(
        private readonly HrisBalanceService $saldo,
        private readonly HrisCalendar $calendar,
        private readonly HrisRequestService $pengajuan,
        private readonly HrisAuditService $audit,
        private readonly JadwalService $jadwal,
    ) {}

    /** @return array{diberi:int} */
    public function beriPhHarian(?string $today = null): array
    {
        $today = PunchRules::tglValid($today ?? PunchRules::tglWib(PunchRules::ms()));
        if ($today === '') {
            throw new HrisError('validation_failed', 'Tanggal harus YYYY-MM-DD.', 422);
        }
        $dari = date('Y-m-d', strtotime($today.' -8 days'));
        $diberi = 0;
        $masuk = HrisKetukan::query()->where('arah', 'MASUK')->where('status', 'VALID')
            ->whereBetween('tanggal_kerja', [$dari, $today])->get();
        foreach ($masuk as $m) {
            if (! $this->calendar->isHoliday($m->tanggal_kerja)) {
                continue;
            }
            $key = $m->user_id.'|'.$m->tanggal_kerja;
            $sudah = HrisSaldoMutasi::query()->where('sumber_tipe', 'KETUKAN_LIBUR')
                ->where('sumber_id', $key)->exists();
            $this->saldo->earn($m->user_id, 'PH', 1, 'KETUKAN_LIBUR', $key, $m->tanggal_kerja,
                HrisBalanceService::tambahBulan($m->tanggal_kerja, 3),
                'Masuk hari libur '.$m->tanggal_kerja);
            if (! $sudah) {
                $diberi++;
            }
        }
        $this->audit->record('scheduler', 'ph_harian', 'hris_saldo_mutasi', $today, ['diberi' => $diberi]);

        return ['diberi' => $diberi];
    }

    /** @return array{hangus:int,baris:int} */
    public function hanguskanPh(?string $today = null): array
    {
        $today = PunchRules::tglValid($today ?? PunchRules::tglWib(PunchRules::ms()));
        $out = $this->saldo->hanguskan($today);
        $this->audit->record('scheduler', 'ph_hangus', 'hris_saldo_mutasi', $today, $out);

        return $out;
    }

    /** @return array{dipakai:int,terlewat:array<int,string>} */
    public function syncCutiJadwalLama(): array
    {
        $out = $this->pengajuan->syncCutiJadwalLama();
        $this->audit->record('scheduler', 'cuti_jadwal_lama', 'hris_saldo_mutasi', '', $out);

        return $out;
    }

    /**
     * Jadwal write-back reconciliation: approval writes roster cells on the
     * legacy Jadwal connection OUTSIDE the core transaction (two databases
     * cannot share one), so a core commit failure after the cells are
     * written leaves approved requests without cells. Missing cells are
     * reported to the audit trail for HR to re-decide; only a fully absent
     * cell counts (heads may legitimately re-edit a written cell).
     *
     * @return array{diperiksa:int,hilang:array<int,string>}
     */
    public function rekonsiliasiJadwal(?string $today = null): array
    {
        $today = PunchRules::tglValid($today ?? PunchRules::tglWib(PunchRules::ms()));
        $dari = date('Y-m-d', strtotime($today.' -60 days'));
        $rows = HrisPengajuan::query()->where('status', 'DISETUJUI')
            ->where('tgl_mulai', '<=', $today)->where('tgl_selesai', '>=', $dari)->get();
        $hilang = [];
        $diperiksa = 0;
        foreach ($rows as $row) {
            $diperiksa++;
            try {
                $sel = $this->jadwal->shiftRange($row->user_id,
                    Carbon::parse($row->tgl_mulai)->format('Y-m-d'),
                    Carbon::parse($row->tgl_selesai)->format('Y-m-d'))['rows'] ?? [];
            } catch (\Throwable) {
                continue; // roster unreadable: the next run retries
            }
            $ada = [];
            foreach ($sel as $s) {
                $ada[(string) ($s['d'] ?? '')] = true;
            }
            $d = Carbon::parse($row->tgl_mulai);
            $akhir = Carbon::parse($row->tgl_selesai);
            while ($d->lte($akhir)) {
                if (! isset($ada[$d->format('Y-m-d')])) {
                    $hilang[] = $row->user_id.'|'.$d->format('Y-m-d').'|'.$row->id;
                }
                $d = $d->addDay();
            }
        }
        if ($hilang !== []) {
            $this->audit->record('scheduler', 'jadwal_sel_hilang', 'hris_pengajuan', $today,
                ['hilang' => array_values(array_slice($hilang, 0, 50))]);
        }

        return ['diperiksa' => $diperiksa, 'hilang' => $hilang];
    }

    /** Earn PH for one punch that just turned VALID (the decide hook). */
    public function syncPhForKetukan(string $id): void
    {
        $m = HrisKetukan::query()->find(trim($id));
        if (! $m || $m->arah !== 'MASUK' || $m->status !== 'VALID') {
            return;
        }
        if (! $this->calendar->isHoliday($m->tanggal_kerja)) {
            return;
        }
        $this->saldo->earn($m->user_id, 'PH', 1, 'KETUKAN_LIBUR',
            $m->user_id.'|'.$m->tanggal_kerja, $m->tanggal_kerja,
            HrisBalanceService::tambahBulan($m->tanggal_kerja, 3),
            'Masuk hari libur '.$m->tanggal_kerja);
    }
}
