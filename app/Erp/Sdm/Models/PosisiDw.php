<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A position a Pekerja Harian can be hired for. */
final class PosisiDw extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'posisi_dw';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
        ];
    }
}
