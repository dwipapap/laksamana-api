<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** An issued ticket with its QR token. */
final class Tiket extends CoreRecord
{
    protected $table = 'tiket';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'diterbitkan_at' => 'immutable_datetime',
        ];
    }
}
