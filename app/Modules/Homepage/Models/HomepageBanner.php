<?php

declare(strict_types=1);

namespace App\Modules\Homepage\Models;

use App\Core\Models\CoreRecord;

/**
 * One row of the website promo slider: an uploaded banner (`sumber = unggah`)
 * or the switch of a BD OS promo (`sumber = bd`). `tampil` says whether it may
 * appear; the eligibility rules live in `HomepagePromos::eligibility()`.
 * Greenfield on `core` (ADR-0003: ULID key, version, created_by/updated_by).
 */
final class HomepageBanner extends CoreRecord
{
    protected $table = 'homepage_banner';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tampil' => 'boolean',
            'urutan' => 'integer',
        ];
    }
}
