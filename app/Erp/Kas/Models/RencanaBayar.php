<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;

/** One Planning Pembayaran sheet: the payments of one payment date. */
final class RencanaBayar extends CoreRecord
{
    protected $table = 'rencana_bayar';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bayar' => 'immutable_date',
        ];
    }
}
