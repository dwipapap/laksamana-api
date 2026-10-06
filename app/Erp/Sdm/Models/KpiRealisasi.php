<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** What one KPI item reached in one month. */
final class KpiRealisasi extends CoreRecord
{
    protected $table = 'kpi_realisasi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bulan' => 'immutable_date',
        ];
    }
}
