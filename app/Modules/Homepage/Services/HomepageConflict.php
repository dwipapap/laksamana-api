<?php

declare(strict_types=1);

namespace App\Modules\Homepage\Services;

use RuntimeException;

/** Optimistic-concurrency failure: missing `If-Match` version, or a stale one. */
class HomepageConflict extends RuntimeException
{
    public function __construct(string $reason, public readonly mixed $current = null)
    {
        parent::__construct($reason);
    }
}
