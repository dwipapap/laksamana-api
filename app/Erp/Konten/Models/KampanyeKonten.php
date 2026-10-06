<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A campaign grouping content of a brand. */
final class KampanyeKonten extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'kampanye_konten';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'mulai' => 'immutable_date',
            'selesai' => 'immutable_date',
        ];
    }
}
