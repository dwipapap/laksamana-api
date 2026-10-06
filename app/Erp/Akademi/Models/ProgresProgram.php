<?php

declare(strict_types=1);

namespace App\Erp\Akademi\Models;

use App\Core\Models\CoreRecord;

/** One User's progress on one material within a programme. */
final class ProgresProgram extends CoreRecord
{
    protected $table = 'progres_program';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'selesai' => 'boolean',
            'dicatat_at' => 'immutable_datetime',
        ];
    }
}
