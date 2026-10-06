<?php

declare(strict_types=1);

namespace App\Core\Models;

/** A notification for one User from one Modul. */
final class Notifikasi extends CoreRecord
{
    protected $table = 'notifikasi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'dibaca_at' => 'immutable_datetime',
        ];
    }
}
