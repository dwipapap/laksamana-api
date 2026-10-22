<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One crew member's shift change for one work date (issue #346):
 * MENUNGGU -> DISETUJUI / DITOLAK / DIBATALKAN. Source and target carry
 * frozen shift snapshots; the official roster cell changes only when the
 * head (or fallback HR) approves and the Jadwal write succeeds.
 */
final class HrisUbahShift extends CoreRecord
{
    public const MENUNGGU = 'MENUNGGU';

    public const DISETUJUI = 'DISETUJUI';

    public const DITOLAK = 'DITOLAK';

    public const DIBATALKAN = 'DIBATALKAN';

    protected $table = 'hris_ubah_shift';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_kerja' => 'immutable_date',
            'shift_lama' => 'array',
            'shift_baru' => 'array',
            'diajukan_at' => 'integer',
            'diputus_at' => 'integer',
        ];
    }
}
