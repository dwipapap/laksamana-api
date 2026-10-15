<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One clock-in (MASUK) or clock-out (PULANG). UNIQUE user_id+tanggal_kerja+arah:
 * a repeated MASUK answers duplikat, a repeated PULANG overwrites (and clears
 * the previous decision). Lateness and duration are never stored — recompute
 * from punches + shifts on read, like AbsensiService::hitungHari().
 */
final class HrisKetukan extends CoreRecord
{
    protected $table = 'hris_ketukan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'waktu' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'akurasi_m' => 'integer',
            'jarak_m' => 'integer',
            'dalam_area' => 'boolean',
            'lintas_hari' => 'boolean',
            'dalam_shift' => 'boolean',
            'skor_wajah' => 'float',
            'wajah_ok' => 'boolean',
            'alasan_antrean' => 'array',
            'diputus_at' => 'integer',
        ];
    }
}
