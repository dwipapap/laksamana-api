<?php

declare(strict_types=1);

namespace App\Erp\Resep\Models;

use App\Core\Models\CoreRecord;

/** The selling price of one yield of a recipe, from berlaku_dari on. */
final class ResepHarga extends CoreRecord
{
    protected $table = 'resep_harga';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
