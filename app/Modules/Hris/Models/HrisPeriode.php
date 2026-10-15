<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One meal-allowance month: TERBUKA until locked, then immutable.
 */
final class HrisPeriode extends CoreRecord
{
    protected $table = 'hris_periode';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [];
    }
}
