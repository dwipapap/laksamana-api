<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** The People Score of one Karyawan for a closed month, as it was then. */
final class SkorKinerja extends CoreRecord
{
    protected $table = 'skor_kinerja';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bulan' => 'immutable_date',
            'ditutup_at' => 'immutable_datetime',
        ];
    }
}
