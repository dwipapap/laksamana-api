<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An attendance location: GPS point + radius. Punches inside the radius are
 * "dalam area"; anything else queues as LUAR_AREA. Master data (soft-deleted).
 */
final class HrisLokasi extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'hris_lokasi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'lat' => 'float',
            'lng' => 'float',
            'radius_m' => 'integer',
            'aktif' => 'boolean',
        ];
    }
}
