<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

final class HrisPengumumanGrant extends CoreRecord
{
    protected $table = 'hris_pengumuman_grant';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'all_divisions' => 'boolean',
            'division_ids' => 'array',
        ];
    }
}
