<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A walk-in party waiting for a table on one business date. */
final class DaftarTunggu extends CoreRecord
{
    protected $table = 'daftar_tunggu';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'masuk_at' => 'immutable_datetime',
            'duduk_at' => 'immutable_datetime',
        ];
    }
}
