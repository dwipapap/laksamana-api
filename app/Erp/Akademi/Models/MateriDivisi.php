<?php

declare(strict_types=1);

namespace App\Erp\Akademi\Models;

use App\Core\Models\CoreRecord;

/** A Divisi a material is meant for; none means every Divisi. */
final class MateriDivisi extends CoreRecord
{
    protected $table = 'materi_divisi';

    protected $guarded = [];
}
