<?php

declare(strict_types=1);

namespace App\Erp\Resep\Models;

use App\Core\Models\CoreRecord;

/** COGS targets, spare and Kontrol Bahan Baku thresholds, from berlaku_dari on. */
final class PengaturanHpp extends CoreRecord
{
    protected $table = 'pengaturan_hpp';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
