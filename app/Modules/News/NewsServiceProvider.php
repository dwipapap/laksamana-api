<?php

declare(strict_types=1);

namespace App\Modules\News;

use App\Modules\News\Console\NewsSeedCommand;
use Illuminate\Support\ServiceProvider;

/** Registers the news module's console commands (greenfield: no legacy route, no importer). */
class NewsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([NewsSeedCommand::class]);
    }
}
