<?php

declare(strict_types=1);

namespace App\Modules\Homepage;

use Illuminate\Support\ServiceProvider;

/**
 * The homepage module (greenfield on `core`): it owns only the `homepage_event`
 * switch. There is no legacy route, no importer and no console command — the
 * event data itself belongs to the Event (EMS) module.
 */
class HomepageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}
