<?php

declare(strict_types=1);

namespace App\Core\Imports;

interface Importer
{
    public function module(): string;

    /** @return list<string> every legacy connection the import reads (all must be local) */
    public function legacyConnections(): array;

    public function targetConnection(): string;

    /** Import from the legacy connection into the target; return rows processed. */
    public function import(): int;
}
