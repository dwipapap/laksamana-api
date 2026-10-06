<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Models;

use App\Core\Models\CoreRecord;

/** One approver of a PR, in order, with when and by whom it was approved. */
final class PengajuanPembelianPenyetuju extends CoreRecord
{
    protected $table = 'pengajuan_pembelian_penyetuju';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'disetujui_at' => 'immutable_datetime',
        ];
    }
}
