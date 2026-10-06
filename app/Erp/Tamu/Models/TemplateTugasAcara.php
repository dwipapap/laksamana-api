<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A task every Acara gets, due some days before it. */
final class TemplateTugasAcara extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'template_tugas_acara';

    protected $guarded = [];
}
