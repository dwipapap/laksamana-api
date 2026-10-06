<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An event idea, before it becomes an Event. */
final class EventIde extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'event_ide';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'evaluasi' => 'array',
            'pernah_dijalankan' => 'boolean',
        ];
    }
}
