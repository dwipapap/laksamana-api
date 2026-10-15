<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One enrolled face. At most one AKTIF row per user (UNIQUE user_id+status);
 * an HR reset flips AKTIF to DIRESET instead of deleting, so re-enrolment is
 * only possible after a reset. The lifecycle is status-driven (no
 * SoftDeletes: a soft-deleted DIRESET row would still hold the unique slot).
 * The descriptor never leaves the server.
 */
final class HrisWajah extends CoreRecord
{
    protected $table = 'hris_wajah';

    protected $guarded = [];

    protected $hidden = ['descriptor'];

    protected function casts(): array
    {
        return parent::casts() + [
            'descriptor' => 'array',
            'consent_at' => 'immutable_datetime',
        ];
    }
}
