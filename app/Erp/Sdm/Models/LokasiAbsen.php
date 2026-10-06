<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A GPS point and radius where punches count as on site. */
final class LokasiAbsen extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'lokasi_absen';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
        ];
    }
}
