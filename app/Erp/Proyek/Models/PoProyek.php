<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Models;

use App\Core\Models\CoreRecord;

/** One PO Proyek line: what is bought, requested and really spent. */
final class PoProyek extends CoreRecord
{
    protected $table = 'po_proyek';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'butuh_tanggal' => 'immutable_date',
            'disetujui_at' => 'immutable_datetime',
            'dibeli_at' => 'immutable_datetime',
            'diproses_at' => 'immutable_datetime',
            'dibatalkan_at' => 'immutable_datetime',
        ];
    }
}
