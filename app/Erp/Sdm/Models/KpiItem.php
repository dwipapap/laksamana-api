<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** One KPI item: target, direction and weight. */
final class KpiItem extends CoreRecord
{
    protected $table = 'kpi_item';

    protected $guarded = [];
}
