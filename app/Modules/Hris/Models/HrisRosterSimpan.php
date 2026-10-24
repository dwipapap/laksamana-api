<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One roster save that changed cells (owner decision 2026-10-24): a Kepala
 * Divisi publishes directly and HR reads this log in Kelola. Append-only;
 * its cell events carry `simpan_id`.
 */
final class HrisRosterSimpan extends CoreRecord
{
    public const HR = 'HR';

    public const KEPALA = 'KEPALA';

    protected $table = 'hris_roster_simpan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'waktu' => 'integer',
            'divisi' => 'array',
            'tanggal_dari' => 'immutable_date',
            'tanggal_sampai' => 'immutable_date',
            'jumlah_isi' => 'integer',
            'jumlah_hapus' => 'integer',
            'jumlah_berubah' => 'integer',
        ];
    }
}
