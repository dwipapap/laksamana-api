<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Meal rate per (divisi, grade), effective-from (P2). Whole rupiah.
 */
final class HrisTarifUangMakan extends CoreRecord
{
    protected $table = 'hris_tarif_uang_makan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['nominal' => 'integer', 'berlaku_dari' => 'immutable_date'];
    }
}
