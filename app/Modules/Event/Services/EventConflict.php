<?php

namespace App\Modules\Event\Services;

use RuntimeException;

/**
 * 'exists' (create with a taken id), 'stale' (version mismatch; $current holds
 * the live record), 'version_required' (writing over an existing row without
 * a version) or 'duplicate' (a UNIQUE column such as tickets.qr_token).
 */
class EventConflict extends RuntimeException
{
    public function __construct(string $kind, public readonly mixed $current = null)
    {
        parent::__construct($kind);
    }
}
