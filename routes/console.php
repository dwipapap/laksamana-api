<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// HRIS daily job (B2: PH for holiday work, PH expiry, CUTI catch-up from
// the old Jadwal panel). The cPanel cron `* * * * * php artisan schedule:run`
// drives this (docs/deploy.md); the job is idempotent, so a missed day
// backfills on the next run.
Schedule::command('hris:harian')->dailyAt('01:10')->timezone('Asia/Jakarta');
