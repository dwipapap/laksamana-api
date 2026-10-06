<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;

/** One approval stage of a piece of content. */
final class KontenPersetujuan extends CoreRecord
{
    protected $table = 'konten_persetujuan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'diputuskan_at' => 'immutable_datetime',
        ];
    }
}
