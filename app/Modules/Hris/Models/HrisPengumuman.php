<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

final class HrisPengumuman extends CoreRecord
{
    protected $table = 'hris_pengumuman';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'audience_all' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }
}
