<?php

declare(strict_types=1);

namespace App\Core\Models;

/** One entry of the activity log every Modul shares; it outlives its object. */
final class LogAktivitas extends CoreRecord
{
    protected $table = 'log_aktivitas';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'detail' => 'array',
            'terjadi_at' => 'immutable_datetime',
        ];
    }
}
