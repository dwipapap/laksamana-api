<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;

/** A KOL's rates from berlaku_dari on. */
final class KolTarif extends CoreRecord
{
    protected $table = 'kol_tarif';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
