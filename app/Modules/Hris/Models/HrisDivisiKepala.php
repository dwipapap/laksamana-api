<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/** Kepala Divisi of a kantor Divisi; shift Divisi heads stay in Jadwal (ADR-0012). */
final class HrisDivisiKepala extends CoreRecord
{
    protected $table = 'hris_divisi_kepala';

    protected $guarded = [];
}
