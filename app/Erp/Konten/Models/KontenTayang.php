<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;

/** Content published on one platform, with that platform's metrics. */
final class KontenTayang extends CoreRecord
{
    protected $table = 'konten_tayang';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tayang_at' => 'immutable_datetime',
        ];
    }
}
