<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** Management's decision on an Acara's quotation. */
final class AcaraPersetujuan extends CoreRecord
{
    protected $table = 'acara_persetujuan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'diputuskan_at' => 'immutable_datetime',
            'otomatis' => 'boolean',
        ];
    }
}
