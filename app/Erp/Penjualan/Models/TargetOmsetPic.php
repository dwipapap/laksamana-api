<?php

declare(strict_types=1);

namespace App\Erp\Penjualan\Models;

use App\Core\Models\CoreRecord;

/** The share of a sales target set for one PIC in one role. */
final class TargetOmsetPic extends CoreRecord
{
    protected $table = 'target_omset_pic';

    protected $guarded = [];
}
