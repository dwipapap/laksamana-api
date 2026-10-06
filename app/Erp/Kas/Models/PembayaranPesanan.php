<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;

/** A Pesanan Bahan covered by a Tagihan Vendor. */
final class PembayaranPesanan extends CoreRecord
{
    protected $table = 'pembayaran_pesanan';

    protected $guarded = [];
}
