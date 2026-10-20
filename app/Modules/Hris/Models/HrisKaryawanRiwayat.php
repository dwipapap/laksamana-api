<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/** One change of Divisi, Jabatan, atasan or status kerja (Q37). Append-only. */
final class HrisKaryawanRiwayat extends CoreRecord
{
    protected $table = 'hris_karyawan_riwayat';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['berlaku_dari' => 'immutable_date'];
    }
}
