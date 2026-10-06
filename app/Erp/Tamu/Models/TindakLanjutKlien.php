<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;

/** A follow-up with a Klien or on an Acara, and when to follow up next. */
final class TindakLanjutKlien extends CoreRecord
{
    protected $table = 'tindak_lanjut_klien';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'berikutnya' => 'immutable_date',
            'dicatat_at' => 'immutable_datetime',
        ];
    }
}
