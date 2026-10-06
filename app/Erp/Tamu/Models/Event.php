<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An event with its approval trail and planning notes. */
final class Event extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'event';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'mulai_at' => 'immutable_datetime',
            'selesai_at' => 'immutable_datetime',
            'diajukan_at' => 'immutable_datetime',
            'diputuskan_at' => 'immutable_datetime',
            'bertiket' => 'boolean',
            'rencana' => 'array',
        ];
    }
}
