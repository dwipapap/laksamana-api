<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A recurring talent slot (days as a bitmask) that generates schedules. */
final class AturanJadwalTalent extends CoreRecord
{
    protected $table = 'aturan_jadwal_talent';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
            'berlaku_sampai' => 'immutable_date',
        ];
    }
}
