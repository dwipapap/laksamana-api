<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A payment on an Acara (DP, settlement, …) with its receipt number. */
final class AcaraPembayaran extends CoreRecord
{
    protected $table = 'acara_pembayaran';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
            'terverifikasi' => 'boolean',
            'dibatalkan_at' => 'immutable_datetime',
        ];
    }
}
