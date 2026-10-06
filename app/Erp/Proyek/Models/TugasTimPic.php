<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Models;

use App\Core\Models\CoreRecord;

/** A PIC of a team task; urutan 0 is the lead. */
final class TugasTimPic extends CoreRecord
{
    protected $table = 'tugas_tim_pic';

    protected $guarded = [];
}
