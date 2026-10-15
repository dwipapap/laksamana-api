<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisAudit;

/**
 * Append-only audit trail. Records who did what to which HRIS object —
 * enrolments, duplicate-face rejections, queue decisions, resets, imports,
 * settings changes. Never updated, never deleted; `data` carries ids and
 * codes only, never biometrics or coordinates.
 */
final class HrisAuditService
{
    public function record(string $aktor, string $aksi, string $refTipe = '', string $refId = '', array $data = []): void
    {
        HrisAudit::query()->create([
            'waktu' => PunchRules::ms(),
            'aktor' => mb_substr(trim($aktor), 0, 120),
            'aksi' => mb_substr(trim($aksi), 0, 64),
            'ref_tipe' => mb_substr(trim($refTipe), 0, 32),
            'ref_id' => mb_substr(trim($refId), 0, 64),
            'data' => $data === [] ? null : $data,
        ]);
    }
}
