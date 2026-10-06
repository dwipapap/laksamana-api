<?php

declare(strict_types=1);

namespace App\Erp\Akademi\Models;

use App\Core\Models\CoreRecord;

/** The default passing score, from berlaku_dari on. */
final class PengaturanAkademi extends CoreRecord
{
    protected $table = 'pengaturan_akademi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
