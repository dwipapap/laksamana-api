<?php

declare(strict_types=1);

namespace App\Erp\Penjualan\Models;

use App\Core\Models\CoreRecord;

/** The monthly sales target of a Lokasi, from berlaku_dari on. */
final class TargetOmset extends CoreRecord
{
    protected $table = 'target_omset';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
            'pakai_hari_kerja' => 'boolean',
        ];
    }
}
