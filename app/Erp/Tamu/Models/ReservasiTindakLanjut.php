<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A follow-up note on a reservation. */
final class ReservasiTindakLanjut extends CoreRecord
{
    protected $table = 'reservasi_tindak_lanjut';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'dicatat_at' => 'immutable_datetime',
        ];
    }
}
