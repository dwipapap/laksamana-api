<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;

/** The part of a Setoran that comes from one day's cash. */
final class SetoranHari extends CoreRecord
{
    protected $table = 'setoran_hari';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
        ];
    }
}
