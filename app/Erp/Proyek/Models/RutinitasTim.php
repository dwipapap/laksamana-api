<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A recurring piece of team work. */
final class RutinitasTim extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'rutinitas_tim';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'penting' => 'boolean',
            'mendesak' => 'boolean',
            'aktif' => 'boolean',
        ];
    }
}
