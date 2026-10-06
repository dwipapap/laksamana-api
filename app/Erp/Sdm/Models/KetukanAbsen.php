<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** One clock-in or clock-out, with GPS, face and shift as they were then. */
final class KetukanAbsen extends CoreRecord
{
    protected $table = 'ketukan_absen';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'waktu' => 'immutable_datetime',
            'dalam_area' => 'boolean',
            'wajah_ok' => 'boolean',
            'dalam_shift' => 'boolean',
            'diputuskan_at' => 'immutable_datetime',
        ];
    }
}
