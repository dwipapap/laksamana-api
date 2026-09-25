<?php

namespace App\Modules\Stock\Services;

use RuntimeException;

/** A training.php refusal that already knows its legacy HTTP status. */
class StockTrainingError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
