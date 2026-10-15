<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One KPI item: target, direction, weight, source.
 */
final class HrisKpiItem extends CoreRecord
{
    protected $table = 'hris_kpi_item';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['target' => 'float', 'bobot' => 'float'];
    }
}
