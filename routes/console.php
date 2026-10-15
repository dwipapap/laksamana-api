<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// HRIS daily jobs (B2: PH for holiday work, PH expiry, CUTI catch-up from
// the old Jadwal panel). The cPanel cron `* * * * * php artisan schedule:run`
// drives this (docs/deploy.md); every job is idempotent, so a missed day
// backfills on the next run.
Schedule::command('hris:harian')->dailyAt('01:10')->timezone('Asia/Jakarta');

// HRIS reminders (B4): lateness sweeps often during work hours, the rest
// daily in the morning. Every send is idempotent, so overlaps are harmless.
Schedule::command('hris:pengingat telat')->everyTenMinutes()->between('5:00', '23:00')->timezone('Asia/Jakarta');
Schedule::command('hris:pengingat harian')->dailyAt('07:00')->timezone('Asia/Jakarta');
