<?php

declare(strict_types=1);

namespace App\Erp\Penjualan\Models;

use App\Core\Models\CoreRecord;

/** Input Omset Harian of one Lokasi on one business date. */
final class OmsetHarian extends CoreRecord
{
    protected $table = 'omset_harian';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'jejak' => 'array',
            'breakdown_valid' => 'boolean',
        ];
    }
}
