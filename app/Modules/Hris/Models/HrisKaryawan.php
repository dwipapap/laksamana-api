<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The HR record of a User (ADR-0012), one per legacy User id. Login, name,
 * PIN, phone and email stay on the Office User; a Karyawan is never
 * hard-deleted (it leaves with tanggal_keluar + alasan_keluar).
 */
final class HrisKaryawan extends CoreRecord
{
    use SoftDeletes;

    public const STATUS_KERJA = ['PROBATION', 'KONTRAK', 'TETAP', 'MAGANG'];

    public const ALASAN_KELUAR = ['RESIGN', 'KONTRAK_HABIS', 'PHK', 'LAINNYA'];

    protected $table = 'hris_karyawan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_lahir' => 'immutable_date',
            'tanggal_gabung' => 'immutable_date',
            'akhir_percobaan' => 'immutable_date',
            'akhir_kontrak' => 'immutable_date',
            'tanggal_keluar' => 'immutable_date',
        ];
    }

    public function divisi(): BelongsTo
    {
        return $this->belongsTo(HrisDivisi::class, 'divisi_id');
    }

    public function jabatan(): BelongsTo
    {
        return $this->belongsTo(HrisJabatan::class, 'jabatan_id');
    }
}
