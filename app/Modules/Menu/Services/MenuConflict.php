<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use RuntimeException;

/** Optimistic-concurrency failure: stale `If-Match`, missing version, or a duplicate slug. */
class MenuConflict extends RuntimeException
{
    public function __construct(string $reason, public readonly mixed $current = null)
    {
        parent::__construct($reason);
    }
}
