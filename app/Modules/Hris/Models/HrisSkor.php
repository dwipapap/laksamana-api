<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Monthly People Score snapshot (S3): locked rows keep their formula.
 */
final class HrisSkor extends CoreRecord
{
    protected $table = 'hris_skor';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['formula_versi' => 'integer', 'komponen' => 'array', 'skor' => 'float', 'kelengkapan' => 'float', 'terkunci' => 'boolean'];
    }
}
