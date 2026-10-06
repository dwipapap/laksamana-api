<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An idea in the content idea bank. */
final class IdeKonten extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'ide_konten';

    protected $guarded = [];
}
