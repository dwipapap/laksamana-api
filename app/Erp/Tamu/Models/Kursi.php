<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A seat, table or area on an event's floor plan. */
final class Kursi extends CoreRecord
{
    protected $table = 'kursi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tata_letak' => 'array',
        ];
    }
}
