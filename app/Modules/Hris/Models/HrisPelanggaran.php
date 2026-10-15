<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Discipline record (S4): status-driven, never hard-deleted.
 */
final class HrisPelanggaran extends CoreRecord
{
    protected $table = 'hris_pelanggaran';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['tanggal' => 'immutable_date', 'sp_ke' => 'integer'];
    }
}
