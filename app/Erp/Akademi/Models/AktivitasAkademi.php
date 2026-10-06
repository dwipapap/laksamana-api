<?php

declare(strict_types=1);

namespace App\Erp\Akademi\Models;

use App\Core\Models\CoreRecord;

/** One entry of the Akademi activity log. */
final class AktivitasAkademi extends CoreRecord
{
    protected $table = 'aktivitas_akademi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'detail' => 'array',
            'terjadi_at' => 'immutable_datetime',
        ];
    }
}
