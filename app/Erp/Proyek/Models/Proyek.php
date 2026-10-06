<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A BD project or event with its budget, stage and PICs. */
final class Proyek extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'proyek';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'mulai' => 'immutable_date',
            'selesai' => 'immutable_date',
        ];
    }
}
