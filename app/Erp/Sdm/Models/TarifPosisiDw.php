<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** The rate of one base shift in a position, from berlaku_dari on. */
final class TarifPosisiDw extends CoreRecord
{
    protected $table = 'tarif_posisi_dw';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
