<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A table of a Lokasi; tata_letak only draws it on the floor plan. */
final class Meja extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'meja';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
            'tata_letak' => 'array',
        ];
    }
}
