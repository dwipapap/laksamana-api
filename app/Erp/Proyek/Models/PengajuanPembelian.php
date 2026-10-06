<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Models;

use App\Core\Models\CoreRecord;

/** A weekly purchase request (PR) whose lines are PO Proyek rows. */
final class PengajuanPembelian extends CoreRecord
{
    protected $table = 'pengajuan_pembelian';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
            'minggu_mulai' => 'immutable_date',
            'diajukan_at' => 'immutable_datetime',
            'selesai_at' => 'immutable_datetime',
        ];
    }
}
