<?php

declare(strict_types=1);

namespace App\Modules\Homepage;

use Illuminate\Support\ServiceProvider;

/**
 * The homepage module (greenfield on `core`): it owns the `homepage_event`
 * switch and the `homepage_banner` rows of the promo slider. There is no legacy
 * route, no importer and no console command — the event data itself belongs to
 * the Event (EMS) module and the promo document to BD.
 */
class HomepageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}
