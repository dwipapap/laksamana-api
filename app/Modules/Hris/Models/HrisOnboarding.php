<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One onboarding per user: checklist + reviews (O1).
 */
final class HrisOnboarding extends CoreRecord
{
    protected $table = 'hris_onboarding';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['mulai' => 'immutable_date'];
    }
}
