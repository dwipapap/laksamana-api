<?php

declare(strict_types=1);

namespace App\Core\Imports;

use InvalidArgumentException;

final class ImporterRegistry
{
    /** @var array<string, Importer> */
    private array $importers = [];

    public function __construct(Importer ...$importers)
    {
        foreach ($importers as $importer) {
            $module = $importer->module();
            if (isset($this->importers[$module])) {
                throw new InvalidArgumentException("Duplicate core importer [$module].");
            }

            $this->importers[$module] = $importer;
        }
    }

    public function has(string $module): bool
    {
        return isset($this->importers[$module]);
    }

    public function get(string $module): Importer
    {
        return $this->importers[$module]
            ?? throw new InvalidArgumentException("No core importer registered for [$module].");
    }

    /** @return list<string> */
    public function modules(): array
    {
        $modules = array_keys($this->importers);
        sort($modules);

        return $modules;
    }
}
