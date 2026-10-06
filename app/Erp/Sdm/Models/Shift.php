<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A shift definition; its end may be past midnight. */
final class Shift extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'shift';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'libur' => 'boolean',
            'aktif' => 'boolean',
        ];
    }
}
