<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A password reset request of a Buyer. */
final class BuyerReset extends CoreRecord
{
    protected $table = 'buyer_reset';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berakhir_at' => 'immutable_datetime',
            'dipakai_at' => 'immutable_datetime',
        ];
    }
}
