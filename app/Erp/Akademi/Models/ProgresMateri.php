<?php

declare(strict_types=1);

namespace App\Erp\Akademi\Models;

use App\Core\Models\CoreRecord;

/** One User's progress on one material. */
final class ProgresMateri extends CoreRecord
{
    protected $table = 'progres_materi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'lulus' => 'boolean',
            'terakhir_at' => 'immutable_datetime',
            'selesai_at' => 'immutable_datetime',
        ];
    }
}
