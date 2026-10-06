<?php

declare(strict_types=1);

namespace App\Erp\Penjualan\Models;

use App\Core\Models\CoreRecord;

/** One voided item of a POS bill, with who entered it and who erred. */
final class VoidItem extends CoreRecord
{
    protected $table = 'void_item';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'dibatalkan_at' => 'immutable_datetime',
        ];
    }
}
