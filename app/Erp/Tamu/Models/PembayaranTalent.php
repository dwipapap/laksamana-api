<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A talent's pay for one month of performances. */
final class PembayaranTalent extends CoreRecord
{
    protected $table = 'pembayaran_talent';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bulan' => 'immutable_date',
            'dibayar_at' => 'immutable_datetime',
        ];
    }
}
