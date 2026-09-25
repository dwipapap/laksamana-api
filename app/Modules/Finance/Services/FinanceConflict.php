<?php

namespace App\Modules\Finance\Services;

use RuntimeException;

/** Stale version: $current is the live value. */
class FinanceConflict extends RuntimeException
{
    public function __construct(public readonly mixed $current)
    {
        parent::__construct('stale');
    }
}
