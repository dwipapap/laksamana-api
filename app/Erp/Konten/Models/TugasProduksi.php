<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;

/** A shooting, design or editing task. */
final class TugasProduksi extends CoreRecord
{
    protected $table = 'tugas_produksi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
        ];
    }
}
