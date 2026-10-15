<?php

declare(strict_types=1);

namespace App\Modules\Hris;

use App\Modules\Hris\Services\HrisCalendar;
use App\Modules\Hris\Services\NullCalendar;
use Illuminate\Support\ServiceProvider;

/**
 * The HRIS module (greenfield on `core`, ADR-0009): attendance, and later
 * leave/PH, meal allowance, People Score, reminders, recruitment and
 * onboarding. There is no legacy route and no importer — other modules' data
 * is reached in-process through their own services.
 *
 * `ModuleServiceProvider` auto-loads `routes/v1.php`; this provider only
 * binds the swappable interfaces (B2 rebinds the calendar to hris_kalender).
 */
class HrisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HrisCalendar::class, NullCalendar::class);
    }

    public function boot(): void
    {
        //
    }
}
