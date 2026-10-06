<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** One booking: guest, pax, table, status and what happened on the day. */
final class Reservasi extends CoreRecord
{
    protected $table = 'reservasi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'berbagi_meja' => 'boolean',
            'member' => 'boolean',
            'vip' => 'boolean',
            'ditutup_otomatis' => 'boolean',
            'checkin_at' => 'immutable_datetime',
            'pulang_at' => 'immutable_datetime',
        ];
    }
}
