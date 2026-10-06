<?php

declare(strict_types=1);

namespace App\Erp\Akses\Models;

use App\Core\Models\CoreRecord;

/** A Kewenangan given to a Peran, with its Lingkup. */
final class PeranKewenangan extends CoreRecord
{
    protected $table = 'peran_kewenangan';

    protected $guarded = [];
}
