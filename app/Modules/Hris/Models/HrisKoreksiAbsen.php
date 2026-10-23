<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One crew member's attendance correction for a past work date: a missing
 * MASUK or PULANG with the requested clock time and reason.
 * MENUNGGU -> DISETUJUI / DITOLAK / DIBATALKAN. The punch row is written
 * only when the head (or fallback HR) approves.
 */
final class HrisKoreksiAbsen extends CoreRecord
{
    public const MENUNGGU = 'MENUNGGU';

    public const DISETUJUI = 'DISETUJUI';

    public const DITOLAK = 'DITOLAK';

    public const DIBATALKAN = 'DIBATALKAN';

    protected $table = 'hris_koreksi_absen';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_kerja' => 'immutable_date',
            'diajukan_at' => 'integer',
            'diputus_at' => 'integer',
        ];
    }
}
