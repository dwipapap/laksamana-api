<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One settings key (nilai JSON, berlaku_dari for history). The merged map is
 * served by HrisSettingsService; unknown keys are refused there, not here.
 */
final class HrisPengaturan extends CoreRecord
{
    protected $table = 'hris_pengaturan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'nilai' => 'array',
            'berlaku_dari' => 'immutable_date',
        ];
    }
}
