<?php

declare(strict_types=1);

namespace App\Modules\Hris;

use App\Modules\Hris\Services\CutiEntitlement;
use App\Modules\Hris\Services\CvScreener;
use App\Modules\Hris\Services\DbCalendar;
use App\Modules\Hris\Services\DefaultCutiEntitlement;
use App\Modules\Hris\Services\HrisCalendar;
use App\Modules\Hris\Services\MetaMuseSparkScreener;
use Illuminate\Support\ServiceProvider;

/**
 * The HRIS module (greenfield on `core`, ADR-0009): attendance, and later
 * leave/PH, meal allowance, People Score, the perhatian box, recruitment
 * and onboarding. There is no legacy route and no importer — other modules' data
 * is reached in-process through their own services.
 *
 * `ModuleServiceProvider` auto-loads `routes/v1.php`; this provider only
 * binds the swappable interfaces.
 */
class HrisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // B2: the calendar reads hris_kalender (B1 bound the false stub).
        $this->app->scoped(HrisCalendar::class, DbCalendar::class);
        // B2: quota from settings until the contract rules land (TODO mentor).
        $this->app->scoped(CutiEntitlement::class, DefaultCutiEntitlement::class);
        // B5: AI screening behind an interface (provider: Meta Muse Spark).
        $this->app->scoped(CvScreener::class, MetaMuseSparkScreener::class);
    }

    public function boot(): void
    {
        //
    }
}
