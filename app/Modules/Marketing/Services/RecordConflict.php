<?php

namespace App\Modules\Marketing\Services;

use RuntimeException;

/** Optimistic-concurrency failure: the stored record changed since the client read it (or already exists). */
class RecordConflict extends RuntimeException
{
    public function __construct(string $reason, public readonly ?array $current)
    {
        parent::__construct($reason);
    }
}
