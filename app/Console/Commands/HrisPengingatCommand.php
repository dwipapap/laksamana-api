<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Hris\Services\HrisReminderService;
use Illuminate\Console\Command;

/**
 * `php artisan hris:pengingat {jenis}` — the HRIS reminder runs (B4).
 * `telat` (every 10 min on the scheduler: no MASUK after the shift starts),
 * `harian` (daily: PH expiry, waiting approvals, study nudges), or `semua`.
 * Every send is idempotent per (jenis, ref, user), so overlapping or missed
 * runs never double-push. Wired in routes/console.php; the cPanel cron
 * drives the scheduler (docs/deploy.md).
 */
final class HrisPengingatCommand extends Command
{
    protected $signature = 'hris:pengingat {jenis=semua : telat|harian|semua}';

    protected $description = 'Kirim pengingat HRIS (telat masuk, PH, persetujuan, belajar)';

    public function handle(HrisReminderService $pengingat): int
    {
        $jenis = (string) $this->argument('jenis');
        if ($jenis === 'telat' || $jenis === 'semua') {
            $r = $pengingat->telatMasuk();
            $this->line('Telat masuk diingatkan: '.$r['diingatkan']);
        }
        if ($jenis === 'harian' || $jenis === 'semua') {
            $ph = $pengingat->phSegeraHangus();
            $this->line('PH segera hangus diingatkan: '.$ph['diingatkan']);
            $tunggu = $pengingat->pengajuanMenunggu();
            $this->line('Persetujuan menunggu diingatkan: '.$tunggu['diingatkan']);
            $ajar = $pengingat->tenggatBelajar();
            $this->line('Tenggat belajar diingatkan: '.$ajar['diingatkan']);
            $ob = $pengingat->onboardingJatuhTempo();
            $this->line('Onboarding lewat tenggat diingatkan: '.$ob['diingatkan']);
        }
        $this->info('Selesai.');

        return self::SUCCESS;
    }
}
