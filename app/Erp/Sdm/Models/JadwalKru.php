<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** One crew member's shift on one date. */
final class JadwalKru extends CoreRecord
{
    protected $table = 'jadwal_kru';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
        ];
    }
}
