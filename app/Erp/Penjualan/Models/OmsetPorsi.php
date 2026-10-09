<?php

declare(strict_types=1);

namespace App\Erp\Penjualan\Models;

use App\Core\Models\CoreRecord;

/** One line of the breakdown: a source (marketing, event, kasir, walk-in) and its PIC. */
final class OmsetPorsi extends CoreRecord
{
    protected $table = 'omset_porsi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'open_bill' => 'boolean',
            'libur' => 'boolean',
        ];
    }
}
