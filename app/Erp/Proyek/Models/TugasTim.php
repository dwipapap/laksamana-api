<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A team task: status, priority, Eisenhower flags, progress and PICs. */
final class TugasTim extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'tugas_tim';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'penting' => 'boolean',
            'mendesak' => 'boolean',
            'tenggat' => 'immutable_date',
        ];
    }
}
