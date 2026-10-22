<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

final class HrisPengumumanDivisi extends CoreRecord
{
    protected $table = 'hris_pengumuman_divisi';

    protected $guarded = [];
}
