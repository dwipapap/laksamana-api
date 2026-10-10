<?php

namespace App\Modules\Finance\Services;

use RuntimeException;

/**
 * Tagihan Rutin write refusals: 'unavailable' (the runtime-created tables are
 * missing — open the old Office panel once so tg_pastikan() creates them),
 * 'not_found' ($current = the legacy message) and 'invalid' ($current = the
 * legacy validation message).
 */
class TagihanConflict extends RuntimeException
{
    public function __construct(public readonly string $kind, public readonly mixed $current = null)
    {
        parent::__construct($kind);
    }
}
