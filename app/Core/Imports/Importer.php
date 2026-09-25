<?php

declare(strict_types=1);

namespace App\Core\Imports;

interface Importer
{
    public function module(): string;

    public function legacyConnection(): string;

    /** Import from the legacy connection into `core`; return rows processed. */
    public function import(): int;
}
