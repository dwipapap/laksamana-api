<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** One arrival of (part of) a booked party. */
final class ReservasiKedatangan extends CoreRecord
{
    protected $table = 'reservasi_kedatangan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'datang_at' => 'immutable_datetime',
        ];
    }
}
