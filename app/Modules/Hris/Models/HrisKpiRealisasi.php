<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Monthly realisation of one KPI item.
 */
final class HrisKpiRealisasi extends CoreRecord
{
    protected $table = 'hris_kpi_realisasi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['nilai' => 'float'];
    }
}
