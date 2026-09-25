<?php

namespace App\Modules\Stock\Services;

use RuntimeException;

/**
 * v1 write refusals: 'not_found', 'exists', 'stale' ($current = live record),
 * 'invalid' ($current = the legacy reason, e.g. "nama produk kosong").
 */
class StockConflict extends RuntimeException
{
    public function __construct(string $kind, public readonly mixed $current = null)
    {
        parent::__construct($kind);
    }
}
