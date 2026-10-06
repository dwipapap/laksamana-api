<?php

declare(strict_types=1);

namespace App\Erp\Akademi\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A monthly programme of materials with a deadline. */
final class ProgramBelajar extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'program_belajar';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bulan' => 'immutable_date',
            'tenggat' => 'immutable_date',
        ];
    }
}
