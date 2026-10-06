<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A paid ad of a brand: platform, objective, budget and status. */
final class Iklan extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'iklan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'mulai' => 'immutable_date',
            'selesai' => 'immutable_date',
        ];
    }
}
