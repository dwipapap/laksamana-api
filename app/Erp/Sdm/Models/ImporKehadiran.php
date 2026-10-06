<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** One monthly Talenta attendance upload. */
final class ImporKehadiran extends CoreRecord
{
    protected $table = 'impor_kehadiran';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bulan' => 'immutable_date',
            'diimpor_at' => 'immutable_datetime',
        ];
    }
}
