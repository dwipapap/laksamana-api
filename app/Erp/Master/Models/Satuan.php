<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A unit name (Gram, Kg, Pcs, Ekor, …); unique regardless of letter case. */
final class Satuan extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'satuan';

    protected $guarded = [];
}
