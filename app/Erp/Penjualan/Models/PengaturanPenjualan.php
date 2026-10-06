<?php

declare(strict_types=1);

namespace App\Erp\Penjualan\Models;

use App\Core\Models\CoreRecord;

/** Tax and service percentages for voids, from berlaku_dari on. */
final class PengaturanPenjualan extends CoreRecord
{
    protected $table = 'pengaturan_penjualan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
