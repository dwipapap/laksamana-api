<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** A Head's request for daily workers on a business date. */
final class PermintaanDw extends CoreRecord
{
    protected $table = 'permintaan_dw';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'diputuskan_at' => 'immutable_datetime',
        ];
    }
}
