<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;

/** Capital paid back to an investor from one Dompet. */
final class PengembalianModal extends CoreRecord
{
    protected $table = 'pengembalian_modal';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'dibatalkan_at' => 'immutable_datetime',
        ];
    }
}
