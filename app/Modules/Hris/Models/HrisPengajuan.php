<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One CUTI/PH/IZIN request: MENUNGGU → MENUNGGU_HRD → DISETUJUI/DITOLAK,
 * DIBATALKAN ends it at any point before (requester) or after (HR, with
 * refund + cell cleanup) approval. head_oleh_id/hrd_oleh_id are legacy
 * User ids for the audit trail and the Jadwal cell write.
 */
final class HrisPengajuan extends CoreRecord
{
    protected $table = 'hris_pengajuan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'hari' => 'integer',
            'tgl_mulai' => 'immutable_date',
            'tgl_selesai' => 'immutable_date',
            'head_at' => 'integer',
            'hrd_at' => 'integer',
        ];
    }
}
