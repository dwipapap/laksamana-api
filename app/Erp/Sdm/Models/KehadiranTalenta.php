<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** One day of one person in a Talenta attendance upload. */
final class KehadiranTalenta extends CoreRecord
{
    protected $table = 'kehadiran_talenta';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
            'hari_kerja' => 'boolean',
            'baris_asli' => 'array',
        ];
    }
}
