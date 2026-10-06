<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;

/** One planned payment; to a vendor Pihak it is a Tagihan Vendor. */
final class Pembayaran extends CoreRecord
{
    protected $table = 'pembayaran';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'jatuh_tempo' => 'immutable_date',
            'dibayar_at' => 'immutable_datetime',
            'bukti_at' => 'immutable_datetime',
            'dibatalkan_at' => 'immutable_datetime',
        ];
    }
}
