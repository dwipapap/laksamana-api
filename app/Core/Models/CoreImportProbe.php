<?php

declare(strict_types=1);

namespace App\Core\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/** Disposable target proving the C1 import and base-model contract. */
final class CoreImportProbe extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'core_import_probe';

    /** The importer preserves the legacy timestamps instead of replacing them. */
    public $timestamps = false;

    protected $guarded = [];
}
