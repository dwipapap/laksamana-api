<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Idempotency for reminders: one row per (jenis, ref, user) pushed.
 * Append-only — a repeated scheduler run never pushes twice.
 */
final class HrisReminderLog extends CoreRecord
{
    protected $table = 'hris_reminder_log';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'waktu' => 'integer',
        ];
    }
}
