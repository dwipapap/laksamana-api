<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;

/** Mutasi Wallet: money moved between Dompet, or put in or taken out. */
final class MutasiDompet extends CoreRecord
{
    protected $table = 'mutasi_dompet';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'dibatalkan_at' => 'immutable_datetime',
        ];
    }
}
