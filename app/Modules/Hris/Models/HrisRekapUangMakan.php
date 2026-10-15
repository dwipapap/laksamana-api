<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Snapshot rows written at lock (P1): never updated, only re-locked never.
 */
final class HrisRekapUangMakan extends CoreRecord
{
    protected $table = 'hris_rekap_uang_makan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['hari_hadir' => 'integer', 'nominal' => 'integer', 'total' => 'integer', 'rincian' => 'array'];
    }
}
