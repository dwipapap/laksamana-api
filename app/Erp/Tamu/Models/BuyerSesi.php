<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A logged-in session of a Buyer. */
final class BuyerSesi extends CoreRecord
{
    protected $table = 'buyer_sesi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berakhir_at' => 'immutable_datetime',
        ];
    }
}
