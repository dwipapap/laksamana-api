<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A temporary hold on a seat while a buyer pays. */
final class KursiTahan extends CoreRecord
{
    protected $table = 'kursi_tahan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berakhir_at' => 'immutable_datetime',
        ];
    }
}
