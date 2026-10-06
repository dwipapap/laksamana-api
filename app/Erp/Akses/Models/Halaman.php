<?php

declare(strict_types=1);

namespace App\Erp\Akses\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A page of a Modul, registered by code; Akses Halaman is set per Peran. */
final class Halaman extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'halaman';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bisa_ubah' => 'boolean',
            'data_per_orang' => 'boolean',
        ];
    }
}
