<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** One scan of a ticket at the gate, or its correction. */
final class CheckinTiket extends CoreRecord
{
    protected $table = 'checkin_tiket';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'dipindai_at' => 'immutable_datetime',
        ];
    }
}
