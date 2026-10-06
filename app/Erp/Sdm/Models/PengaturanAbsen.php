<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** Attendance rules (windows, face threshold, lateness), from berlaku_dari on. */
final class PengaturanAbsen extends CoreRecord
{
    protected $table = 'pengaturan_absen';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berlaku_dari' => 'immutable_date',
            'wajah_wajib' => 'boolean',
            'tanpa_shift_boleh' => 'boolean',
        ];
    }
}
