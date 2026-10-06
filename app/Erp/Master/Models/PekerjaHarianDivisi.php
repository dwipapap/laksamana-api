<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;

/** A Divisi a Pekerja Harian may work in. */
final class PekerjaHarianDivisi extends CoreRecord
{
    protected $table = 'pekerja_harian_divisi';

    protected $guarded = [];
}
