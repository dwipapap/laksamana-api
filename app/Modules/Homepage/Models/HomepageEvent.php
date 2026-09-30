<?php

declare(strict_types=1);

namespace App\Modules\Homepage\Models;

use App\Core\Models\CoreRecord;

/**
 * The website switch for one EMS event: `tampil` says whether it may appear on
 * the public site. The row exists from the first toggle; no row means off.
 * Greenfield on `core` (ADR-0003: ULID key, version, created_by/updated_by).
 */
final class HomepageEvent extends CoreRecord
{
    protected $table = 'homepage_event';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tampil' => 'boolean',
        ];
    }
}
