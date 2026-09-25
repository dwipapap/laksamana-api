<?php

namespace App\Modules\Kompas\Services;

use RuntimeException;

/** A v1 write carried a version older than the stored blob; $current is the stored version. */
class KompasConflict extends RuntimeException
{
    public function __construct(public readonly int $current)
    {
        parent::__construct('stale');
    }
}
