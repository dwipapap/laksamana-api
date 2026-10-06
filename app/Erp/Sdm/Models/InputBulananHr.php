<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** Manual People Score inputs of one Karyawan for one month. */
final class InputBulananHr extends CoreRecord
{
    protected $table = 'input_bulanan_hr';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bulan' => 'immutable_date',
        ];
    }
}
