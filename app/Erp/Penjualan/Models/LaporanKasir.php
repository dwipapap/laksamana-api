<?php

declare(strict_types=1);

namespace App\Erp\Penjualan\Models;

use App\Core\Models\CoreRecord;

/** The cashier's Report Daily of one Lokasi on one business date. */
final class LaporanKasir extends CoreRecord
{
    protected $table = 'laporan_kasir';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'jejak' => 'array',
        ];
    }
}
