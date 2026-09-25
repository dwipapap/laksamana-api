<?php

namespace App\Modules\Hlife\Services;

use RuntimeException;

/** 'exists' (create with a taken id) or 'stale' (version mismatch; $current holds the live row). */
class HlifeConflict extends RuntimeException
{
    public function __construct(string $kind, public readonly ?array $current = null)
    {
        parent::__construct($kind);
    }
}
