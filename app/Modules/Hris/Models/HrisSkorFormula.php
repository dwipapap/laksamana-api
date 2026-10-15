<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Versioned People Score weights (S2).
 */
final class HrisSkorFormula extends CoreRecord
{
    protected $table = 'hris_skor_formula';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['versi' => 'integer', 'komponen' => 'array', 'berlaku_dari' => 'immutable_date'];
    }
}
