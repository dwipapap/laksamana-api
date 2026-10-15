<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Onboarding template per Divisi (O1): non-learning steps + Akademi program link.
 */
final class HrisOnboardingTemplate extends CoreRecord
{
    protected $table = 'hris_onboarding_template';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['langkah' => 'array'];
    }
}
