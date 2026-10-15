<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Hris\Services\HrisHarianService;
use App\Modules\Hris\Services\HrisRekrutmenService;
use App\Modules\Hris\Services\PunchRules;
use Illuminate\Console\Command;

/**
 * `php artisan hris:harian` — the daily HRIS jobs (B2 + B5): PH for holiday
 * work, PH expiry, the CUTI catch-up from the old Jadwal panel, and
 * applicant retention cleanup. Idempotent, so a missed day backfills on the
 * next run. Wired to the scheduler in routes/console.php; the owner
 * installs the cPanel cron (`* * * * * php artisan schedule:run`).
 */
final class HrisHarianCommand extends Command
{
    protected $signature = 'hris:harian';

    protected $description = 'PH harian, PH hangus, sinkron cuti Jadwal lama, retensi pelamar';

    public function handle(HrisHarianService $harian, HrisRekrutmenService $rekrutmen): int
    {
        $ph = $harian->beriPhHarian();
        $this->line('PH diberi: '.$ph['diberi']);
        $hangus = $harian->hanguskanPh();
        $this->line('PH hangus: '.$hangus['hangus'].' ('.$hangus['baris'].' baris)');
        $cuti = $harian->syncCutiJadwalLama();
        $this->line('Cuti Jadwal lama dipakai: '.$cuti['dipakai']);
        if ($cuti['terlewat'] !== []) {
            $this->warn('Terlewat (saldo kurang): '.implode(', ', $cuti['terlewat']));
        }
        $hapus = $rekrutmen->hapusKedaluwarsa(PunchRules::tglWib(PunchRules::ms()));
        $this->line('Pelamar kedaluwarsa dihapus: '.$hapus);
        $this->info('Selesai.');

        return self::SUCCESS;
    }
}
