<?php

namespace App\Modules\Dw\Services;

use Exception;

/** Stale setting version: $current is the stored one. Not a RuntimeException: DwController::run() turns those into 422. */
class DwConflict extends Exception
{
    public function __construct(public readonly int $current)
    {
        parent::__construct('stale');
    }
}
