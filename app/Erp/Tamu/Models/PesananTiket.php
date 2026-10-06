<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A ticket order; paid only through the payment gateway webhook. */
final class PesananTiket extends CoreRecord
{
    protected $table = 'pesanan_tiket';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_lahir' => 'immutable_date',
            'berakhir_at' => 'immutable_datetime',
            'dibayar_at' => 'immutable_datetime',
        ];
    }
}
