<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;

/** Money spent on an ad on one date, with the VAT computed then. */
final class IklanBiaya extends CoreRecord
{
    protected $table = 'iklan_biaya';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
        ];
    }
}
