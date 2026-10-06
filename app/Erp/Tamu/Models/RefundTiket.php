<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A refund request on a ticket order and its decision. */
final class RefundTiket extends CoreRecord
{
    protected $table = 'refund_tiket';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'diminta_at' => 'immutable_datetime',
            'diputuskan_at' => 'immutable_datetime',
        ];
    }
}
