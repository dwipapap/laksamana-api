<?php

declare(strict_types=1);

namespace App\Erp\Penjualan\Models;

use App\Core\Models\CoreRecord;

/** A guest's unpaid bill until it is settled. */
final class Bon extends CoreRecord
{
    protected $table = 'bon';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'lunas_tanggal' => 'immutable_date',
            'lunas_at' => 'immutable_datetime',
            'dibatalkan_at' => 'immutable_datetime',
        ];
    }
}
