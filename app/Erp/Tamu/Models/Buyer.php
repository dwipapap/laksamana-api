<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A public ticket-shop account; never a User. */
final class Buyer extends CoreRecord
{
    protected $table = 'buyer';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_lahir' => 'immutable_date',
        ];
    }
}
