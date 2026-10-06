<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** The default shift of a crew member. */
final class ShiftBawaan extends CoreRecord
{
    protected $table = 'shift_bawaan';

    protected $guarded = [];
}
