<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A ticket class of an event: price, quota and sale window. */
final class KelasTiket extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'kelas_tiket';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bertempat' => 'boolean',
            'jual_mulai' => 'immutable_datetime',
            'jual_selesai' => 'immutable_datetime',
        ];
    }
}
