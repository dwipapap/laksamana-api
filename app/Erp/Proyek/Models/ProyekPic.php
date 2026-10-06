<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Models;

use App\Core\Models\CoreRecord;

/** A PIC of a Proyek; urutan 0 is the lead. */
final class ProyekPic extends CoreRecord
{
    protected $table = 'proyek_pic';

    protected $guarded = [];
}
