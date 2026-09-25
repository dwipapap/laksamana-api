<?php

namespace App\Modules\Bd\Services;

use RuntimeException;

/** 'exists' (create with a taken id) or 'stale' (version mismatch; $current holds the live record). */
class BdConflict extends RuntimeException
{
    public function __construct(string $kind, public readonly ?array $current = null)
    {
        parent::__construct($kind);
    }
}
