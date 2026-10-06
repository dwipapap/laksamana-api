<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** A schedule request (off, leave, swap), approved by the Kepala Divisi then HRD. */
final class PengajuanJadwal extends CoreRecord
{
    protected $table = 'pengajuan_jadwal';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_mulai' => 'immutable_date',
            'tanggal_selesai' => 'immutable_date',
            'head_disetujui_at' => 'immutable_datetime',
            'diputuskan_at' => 'immutable_datetime',
        ];
    }
}
