<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** The lowest score that earns a grade. */
final class PengaturanHrGrade extends CoreRecord
{
    protected $table = 'pengaturan_hr_grade';

    protected $guarded = [];
}
