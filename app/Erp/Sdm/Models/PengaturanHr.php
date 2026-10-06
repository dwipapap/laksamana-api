<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** People Score weights, default and completeness threshold, from berlaku_dari on. */
final class PengaturanHr extends CoreRecord
{
    protected $table = 'pengaturan_hr';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
