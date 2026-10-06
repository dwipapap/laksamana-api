<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A sponsor of an event and what it brings. */
final class EventSponsor extends CoreRecord
{
    protected $table = 'event_sponsor';

    protected $guarded = [];
}
