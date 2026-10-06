<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** The layered review of one Karyawan for one month. */
final class ReviewKinerja extends CoreRecord
{
    protected $table = 'review_kinerja';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bulan' => 'immutable_date',
        ];
    }
}
