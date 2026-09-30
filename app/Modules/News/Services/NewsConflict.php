<?php

declare(strict_types=1);

namespace App\Modules\News\Services;

use RuntimeException;

/** Optimistic-concurrency failure: stale `If-Match`, missing version, a duplicate slug or a kategori in use. */
class NewsConflict extends RuntimeException
{
    public function __construct(string $reason, public readonly mixed $current = null)
    {
        parent::__construct($reason);
    }
}
