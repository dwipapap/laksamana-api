<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One AI screening result with its prompt and model version (R3, advice only).
 */
final class HrisScreening extends CoreRecord
{
    protected $table = 'hris_screening';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['hasil' => 'array', 'waktu' => 'integer'];
    }
}
