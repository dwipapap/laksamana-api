<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Manpower request: head files, HR then Owner approve (R1); approval opens a vacancy.
 */
final class HrisPermintaanTenaga extends CoreRecord
{
    protected $table = 'hris_permintaan_tenaga';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [];
    }
}
