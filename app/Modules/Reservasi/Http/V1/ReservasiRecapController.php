<?php

namespace App\Modules\Reservasi\Http\V1;

use App\Modules\Reservasi\Services\ReservasiRecap;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * GET /api/v1/reservasi/recap — daily recap for the 17:00 WIB automation.
 *
 * Query:
 *   date=YYYY-MM-DD   (optional; default today Asia/Jakarta)
 *   days=0,1,2        (optional; also return H+1, H+2 — max 7)
 *   source=default|prod (optional; prod = pinned PROD reservasi DB)
 *
 * READ-ONLY: no write method exists on ReservasiRecap, and the prod pin
 * must use a SELECT-only DB user. Response carries no phone numbers and
 * no photo blobs.
 */
class ReservasiRecapController
{
    public function __construct(private readonly ReservasiRecap $recap) {}

    public function show(Request $request): JsonResponse
    {
        $v = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:0', 'max:7'],
            'source' => ['nullable', 'in:default,prod'],
        ]);

        $source = $v['source'] ?? 'default';
        if ($source === 'prod' && ! ReservasiRecap::prodPinned()) {
            return ApiResponse::error('not_configured', 'The prod recap source is not configured on this server.', 501);
        }

        try {
            $base = $v['date'] ?? ReservasiRecap::plusDays(0);
            $n = (int) ($v['days'] ?? 0);
            if ($n === 0) {
                $out = $this->recap->daily($base, $source);

                return ApiResponse::ok($out['rows'], [
                    'date' => $out['date'], 'source' => $out['source'], 'total' => $out['total'],
                ]);
            }
            $days = [];
            $baseDt = new \DateTimeImmutable($base);
            for ($i = 0; $i <= $n; $i++) {
                $days[] = $this->recap->daily($baseDt->modify("+{$i} days")->format('Y-m-d'), $source);
            }

            return ApiResponse::ok($days, ['source' => $source, 'days' => $n + 1]);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
    }
}
