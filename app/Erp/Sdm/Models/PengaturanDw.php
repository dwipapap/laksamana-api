<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** Daily-worker wage rules (base shift, long-shift extra), from berlaku_dari on. */
final class PengaturanDw extends CoreRecord
{
    protected $table = 'pengaturan_dw';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
