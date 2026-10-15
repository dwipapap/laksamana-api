<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Team KPI template per Divisi (K1): 3-6 items live on HrisKpiItem.
 */
final class HrisKpiTemplate extends CoreRecord
{
    protected $table = 'hris_kpi_template';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [];
    }
}
