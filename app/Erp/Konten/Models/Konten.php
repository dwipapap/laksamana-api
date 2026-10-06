<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One piece of content moving through the production pipeline. */
final class Konten extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'konten';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tenggat' => 'immutable_date',
            'tanggal_tayang' => 'immutable_date',
            'isi' => 'array',
        ];
    }
}
