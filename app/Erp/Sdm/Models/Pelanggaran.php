<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** A recorded violation of a Karyawan and its severity. */
final class Pelanggaran extends CoreRecord
{
    protected $table = 'pelanggaran';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
        ];
    }
}
