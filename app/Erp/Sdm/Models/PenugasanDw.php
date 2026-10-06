<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** One Pekerja Harian booked for a shift, with the wage copied then. */
final class PenugasanDw extends CoreRecord
{
    protected $table = 'penugasan_dw';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'diputuskan_at' => 'immutable_datetime',
            'kehadiran_at' => 'immutable_datetime',
        ];
    }
}
