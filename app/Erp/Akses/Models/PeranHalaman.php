<?php

declare(strict_types=1);

namespace App\Erp\Akses\Models;

use App\Core\Models\CoreRecord;

/** The Tingkat (and Lingkup) one Peran has on one Halaman. */
final class PeranHalaman extends CoreRecord
{
    protected $table = 'peran_halaman';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tingkat' => 'integer',
        ];
    }
}
