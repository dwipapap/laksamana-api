<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** One budget line of an Event: planned and actual amount. */
final class EventAnggaran extends CoreRecord
{
    protected $table = 'event_anggaran';

    protected $guarded = [];
}
