<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** One ticket class in an order, at the price it had when ordered. */
final class PesananTiketBaris extends CoreRecord
{
    protected $table = 'pesanan_tiket_baris';

    protected $guarded = [];
}
