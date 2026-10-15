<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One holiday: national or company. Read by DbCalendar for the PH earn job
 * (a holiday never queues a punch); written by HR (CRUD + file import).
 */
final class HrisKalender extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'hris_kalender';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
        ];
    }
}
