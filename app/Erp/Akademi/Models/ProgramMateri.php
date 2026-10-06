<?php

declare(strict_types=1);

namespace App\Erp\Akademi\Models;

use App\Core\Models\CoreRecord;

/** A material inside a programme, in order. */
final class ProgramMateri extends CoreRecord
{
    protected $table = 'program_materi';

    protected $guarded = [];
}
