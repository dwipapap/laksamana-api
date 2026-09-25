<?php

declare(strict_types=1);

namespace App\Core\Imports;

interface Importer
{
    public function module(): string;

    public function legacyConnection(): string;

    public function targetConnection(): string;

    /** Import from the legacy connection into the target; return rows processed. */
    public function import(): int;
}
