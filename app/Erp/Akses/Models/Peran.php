<?php

declare(strict_types=1);

namespace App\Erp\Akses\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A role inside one Modul; bawaan = the role of a User without a placement. */
final class Peran extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'peran';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bawaan' => 'boolean',
        ];
    }
}
