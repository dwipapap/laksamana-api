<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;

/** The Konten work profile of a User: capacity, skills, working hours. */
final class KruKonten extends CoreRecord
{
    protected $table = 'kru_konten';

    protected $primaryKey = 'user_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tersedia' => 'boolean',
            'jam_kerja' => 'array',
        ];
    }
}
