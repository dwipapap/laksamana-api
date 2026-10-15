<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Monthly 1-5 superior ratings per member (S5).
 */
final class HrisPenilaianAtasan extends CoreRecord
{
    protected $table = 'hris_penilaian_atasan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['aspek' => 'array'];
    }
}
