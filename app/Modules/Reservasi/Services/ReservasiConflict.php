<?php

namespace App\Modules\Reservasi\Services;

use RuntimeException;

/** 'exists', 'missing' or 'stale' ($current holds the live row). */
class ReservasiConflict extends RuntimeException
{
    public function __construct(string $kind, public readonly ?array $current = null)
    {
        parent::__construct($kind);
    }
}
