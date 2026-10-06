<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** The KPI of a Divisi, from berlaku_dari on. */
final class KpiTemplate extends CoreRecord
{
    protected $table = 'kpi_template';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
