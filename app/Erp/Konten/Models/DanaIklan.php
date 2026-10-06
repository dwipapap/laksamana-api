<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;

/** Money set aside for a brand's ads on a date. */
final class DanaIklan extends CoreRecord
{
    protected $table = 'dana_iklan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
        ];
    }
}
