<?php

declare(strict_types=1);

namespace App\Erp\Penjualan\Models;

use App\Core\Models\CoreRecord;

/** One Metode Bayar in a Report Daily: POS, actual, and what reached the bank. */
final class LaporanKasirBayar extends CoreRecord
{
    protected $table = 'laporan_kasir_bayar';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'sudah_input_pos' => 'boolean',
        ];
    }
}
