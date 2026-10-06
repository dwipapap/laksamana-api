<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** The discipline points one violation severity takes off. */
final class PengaturanHrPotongan extends CoreRecord
{
    protected $table = 'pengaturan_hr_potongan';

    protected $guarded = [];
}
