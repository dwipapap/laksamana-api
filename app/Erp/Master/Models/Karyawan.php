<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The HR record of a User, keyed by user_id (1:1). */
final class Karyawan extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'karyawan';

    protected $primaryKey = 'user_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_lahir' => 'immutable_date',
            'akhir_kontrak' => 'immutable_date',
            'akhir_percobaan' => 'immutable_date',
        ];
    }
}
