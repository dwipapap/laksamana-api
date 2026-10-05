<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Imports\ImporterRegistry;
use App\Core\Imports\ReportsIssues;
use Illuminate\Console\Command;

final class CoreImportCommand extends Command
{
    protected $signature = 'core:import {module? : Registered core importer key} {--list : Print the registered keys, one per line}';

    protected $description = 'Import one registered legacy source into the core database idempotently';

    public function handle(ImporterRegistry $importers): int
    {
        if ($this->option('list')) {
            foreach ($importers->modules() as $key) {
                $this->line($key);
            }

            return self::SUCCESS;
        }
        $module = (string) $this->argument('module');
        if (! $importers->has($module)) {
            $this->components->error("No core importer registered for [{$module}].");
            $this->line('Available: '.implode(', ', $importers->modules()));

            return self::FAILURE;
        }

        $importer = $importers->get($module);
        foreach ([...$importer->legacyConnections(), $importer->targetConnection()] as $connection) {
            if (! $this->isLocalConnection($connection)) {
                $this->components->error("Refusing core:import: {$connection} must use a local host.");

                return self::FAILURE;
            }
        }

        $rows = $importer->import();
        $this->components->info(
            "Imported {$rows} rows from ".implode(', ', $importer->legacyConnections())." into {$importer->targetConnection()}."
        );

        if ($importer instanceof ReportsIssues) {
            foreach ($importer->issues() as $kind => $lines) {
                $this->components->warn(count($lines)." to decide: {$kind}");
                foreach ($lines as $line) {
                    $this->line("  - {$line}");
                }
            }
        }

        return self::SUCCESS;
    }

    private function isLocalConnection(string $connection): bool
    {
        $config = config("database.connections.{$connection}");
        if (! is_array($config)) {
            return false;
        }

        $host = strtolower(trim((string) ($config['host'] ?? '')));
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return true;
        }

        return $host === '' && (string) ($config['unix_socket'] ?? '') !== '';
    }
}
