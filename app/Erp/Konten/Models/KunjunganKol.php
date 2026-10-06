<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;

/** A KOL visit to the venue and how it went. */
final class KunjunganKol extends CoreRecord
{
    protected $table = 'kunjungan_kol';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
        ];
    }
}
