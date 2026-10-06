<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A vendor engaged for an event and what it costs. */
final class EventVendor extends CoreRecord
{
    protected $table = 'event_vendor';

    protected $guarded = [];
}
