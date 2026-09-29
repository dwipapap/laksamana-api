<?php

declare(strict_types=1);

namespace App\Modules\Menu;

use App\Modules\Menu\Console\MenuSeedCommand;
use Illuminate\Support\ServiceProvider;

/** Registers the menu module's console commands (greenfield: no legacy route, no importer). */
class MenuServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([MenuSeedCommand::class]);
    }
}
