<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** One DP instalment of a reservation, with its transfer proof and verification. */
final class ReservasiDp extends CoreRecord
{
    protected $table = 'reservasi_dp';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'transfer_tanggal' => 'immutable_date',
            'ocr_at' => 'immutable_datetime',
            'diverifikasi_at' => 'immutable_datetime',
        ];
    }
}
