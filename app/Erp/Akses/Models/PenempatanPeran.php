<?php

declare(strict_types=1);

namespace App\Erp\Akses\Models;

use App\Core\Models\CoreRecord;

/** The one Peran a User holds in one Modul. */
final class PenempatanPeran extends CoreRecord
{
    protected $table = 'penempatan_peran';

    protected $guarded = [];
}
