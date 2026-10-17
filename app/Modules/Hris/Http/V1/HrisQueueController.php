<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisHarianService;
use App\Modules\Hris\Services\HrisLingkup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — the decision queue. HR sees everything; a Kepala Divisi
 * sees their divisions; crew get 403. Decisions carry the `berubah` version
 * the decider saw: a stale one is a 409, and so is deciding twice.
 */
final class HrisQueueController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisAttendanceService $hadir,
        private readonly HrisLingkup $lingkup,
        private readonly HrisHarianService $harian,
    ) {
        parent::__construct($access);
    }

    public function queue(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->hadir->queue($this->lingkup->queueScope($me), true));
    }

    public function decide(Request $r, string $id): JsonResponse
    {
        // Coarse gate first: crew get 403 even with an invalid body.
        // The fine-grained check (own punch, division) still runs inside.
        $me = $this->me($r);
        $this->lingkup->queueScope($me);
        $d = $r->validate([
            'status' => ['required', 'string', 'max:16'],
            'nota' => ['nullable', 'string', 'max:255'],
            'berubah' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->run(function () use ($me, $id, $d) {
            $row = HrisKetukan::query()->find($id);
            if (! $row) {
                throw new HrisError('not_found', 'Ketukan tidak ada.', 404);
            }
            $this->lingkup->assertMayDecide($me, $row->user_id);
            // The prompt names the field `berubah`; `version` is accepted too.
            // Either way it is the ADR-0007 version column (see migration note).
            $berubah = (int) ($d['berubah'] ?? $d['version'] ?? 0);
            if ($berubah < 1) {
                throw new HrisError('validation_failed', 'Keputusan perlu berubah (versi yang terlihat).', 422);
            }
            $out = $this->hadir->decide($id, $d['status'] ?? '', $d['nota'] ?? '',
                self::s($me['name']), $berubah);
            if (! $out['ok'] && ($out['error'] ?? '') === 'konflik') {
                throw new HrisError('version_conflict',
                    'Sudah diubah orang lain. Muat ulang antrean lalu putuskan lagi.',
                    409, ['current' => $out['current'] ?? 0]);
            }
            if (! $out['ok']) {
                throw new HrisError('already_decided', 'Ketukan ini sudah diputus.', 409);
            }
            // Post-decision hook, swallowed on failure: the decision itself
            // already stands and PH backfills in the daily job.
            try {
                if (strtoupper(self::s($d['status'] ?? '')) === 'VALID') {
                    $this->harian->syncPhForKetukan($id);
                }
            } catch (\Throwable) {
            }

            return ['berubah' => 1];
        });
    }
}
