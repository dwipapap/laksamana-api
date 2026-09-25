<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Imports\ImporterRegistry;
use Illuminate\Console\Command;

final class CoreImportCommand extends Command
{
    protected $signature = 'core:import {module : Registered core importer key}';

    protected $description = 'Import one registered legacy source into the core database idempotently';

    public function handle(ImporterRegistry $importers): int
    {
        $module = (string) $this->argument('module');
        if (! $importers->has($module)) {
            $this->components->error("No core importer registered for [{$module}].");
            $this->line('Available: '.implode(', ', $importers->modules()));

            return self::FAILURE;
        }

        $importer = $importers->get($module);
        $rows = $importer->import();
        $this->components->info("Imported {$rows} rows from {$importer->legacyConnection()} into core.");

        return self::SUCCESS;
    }
}
