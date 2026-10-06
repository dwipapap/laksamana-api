<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** One weekly transfer to one destination account, covering several assignments. */
final class PembayaranDw extends CoreRecord
{
    protected $table = 'pembayaran_dw';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'minggu_mulai' => 'immutable_date',
            'dibayar_at' => 'immutable_datetime',
        ];
    }
}
