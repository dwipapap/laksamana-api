<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** One quotation line of an Acara: description, quantity and price. */
final class AcaraRincian extends CoreRecord
{
    protected $table = 'acara_rincian';

    protected $guarded = [];
}
