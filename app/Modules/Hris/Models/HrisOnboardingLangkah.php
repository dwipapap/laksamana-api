<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One checklist step with an optional deadline.
 */
final class HrisOnboardingLangkah extends CoreRecord
{
    protected $table = 'hris_onboarding_langkah';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['tenggat' => 'immutable_date', 'selesai' => 'boolean', 'selesai_at' => 'integer'];
    }
}
