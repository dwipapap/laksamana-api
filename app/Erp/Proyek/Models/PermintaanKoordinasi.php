<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Models;

use App\Core\Models\CoreRecord;

/** A request from one Divisi to another. */
final class PermintaanKoordinasi extends CoreRecord
{
    protected $table = 'permintaan_koordinasi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tenggat' => 'immutable_date',
        ];
    }
}
