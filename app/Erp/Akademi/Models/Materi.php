<?php

declare(strict_types=1);

namespace App\Erp\Akademi\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A learning material made of ordered steps (text, video, quiz). */
final class Materi extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'materi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'wajib' => 'boolean',
            'terbit' => 'boolean',
            'langkah' => 'array',
        ];
    }
}
