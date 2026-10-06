<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** Schedule rules (max consecutive days, minimum rest), from berlaku_dari on. */
final class PengaturanJadwal extends CoreRecord
{
    protected $table = 'pengaturan_jadwal';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
