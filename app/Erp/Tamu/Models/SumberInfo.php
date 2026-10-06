<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Where a guest heard of the venue (Instagram, Walk-in, …). */
final class SumberInfo extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'sumber_info';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
        ];
    }
}
