<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** One scheduled performance of a talent, at the fee it had then. */
final class JadwalTalent extends CoreRecord
{
    protected $table = 'jadwal_talent';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
        ];
    }
}
