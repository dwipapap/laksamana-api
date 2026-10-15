<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Vacancy with AI screening criteria (R1).
 */
final class HrisLowongan extends CoreRecord
{
    protected $table = 'hris_lowongan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [];
    }
}
