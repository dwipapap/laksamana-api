<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One crew profile change request (issue #344): MENUNGGU -> DISETUJUI /
 * DITOLAK / DIBATALKAN, with DIPROSES as the durable midpoint between the HR
 * decision and the finished Account + HRIS writes. nilai_awal and perubahan
 * hold the requested fields only; the apply flags mark each verified write.
 */
final class HrisPerubahanProfil extends CoreRecord
{
    public const MENUNGGU = 'MENUNGGU';

    public const DIPROSES = 'DIPROSES';

    public const DISETUJUI = 'DISETUJUI';

    public const DITOLAK = 'DITOLAK';

    public const DIBATALKAN = 'DIBATALKAN';

    protected $table = 'hris_perubahan_profil';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'nilai_awal' => 'array',
            'perubahan' => 'array',
            'diajukan_at' => 'integer',
            'diputus_at' => 'integer',
            'apply_account_ok' => 'boolean',
            'apply_core_ok' => 'boolean',
            'apply_percobaan' => 'integer',
        ];
    }
}
