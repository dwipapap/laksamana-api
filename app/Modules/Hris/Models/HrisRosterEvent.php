<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One roster cell change made through HRIS (issue #346), append-only:
 * manual/batch/clear edits, approved shift changes and finalized leave
 * requests. Never updated, never deleted; the next change is a new event.
 */
final class HrisRosterEvent extends CoreRecord
{
    public const UBAH_SHIFT = 'UBAH_SHIFT';

    public const PENGAJUAN = 'PENGAJUAN';

    public const EDIT_MANUAL = 'EDIT_MANUAL';

    public const SISTEM = 'SISTEM';

    protected $table = 'hris_roster_event';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'waktu' => 'integer',
            'tanggal_kerja' => 'immutable_date',
            'sel_lama' => 'array',
            'sel_baru' => 'array',
            'diajukan_at' => 'integer',
            'diputus_at' => 'integer',
        ];
    }
}
