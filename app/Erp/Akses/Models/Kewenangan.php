<?php

declare(strict_types=1);

namespace App\Erp\Akses\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A named action inside a Modul that is not tied to one page. */
final class Kewenangan extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'kewenangan';

    protected $guarded = [];
}
