<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A configurable Jabatan level; a higher `urutan` is more senior. */
final class HrisJabatanLevel extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'hris_jabatan_level';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['urutan' => 'integer', 'aktif' => 'boolean'];
    }
}
