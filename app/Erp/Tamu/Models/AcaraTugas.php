<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** One task of an Acara. */
final class AcaraTugas extends CoreRecord
{
    protected $table = 'acara_tugas';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tenggat' => 'immutable_date',
        ];
    }
}
