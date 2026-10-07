<?php

namespace App\Modules\InfoPagi\Http\V1;

use App\Modules\InfoPagi\Services\InfoPagi;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * GET /api/v1/info/pagi — morning briefing for the 07:00 WIB automation.
 *
 * Query:
 *   date=YYYY-MM-DD   (optional; default today Asia/Jakarta)
 *   source=default|prod (optional; prod = pinned PROD event+marketing DBs)
 *
 * READ-ONLY: no write method exists on InfoPagi, and the prod pins must
 * use a SELECT-only DB user (reuse the recap_ro user, extended with
 * GRANT SELECT on the ems + marketing databases).
 * Response carries no phone numbers and no photo blobs.
 */
class InfoPagiController
{
    public function __construct(private readonly InfoPagi $info) {}

    public function show(Request $request): JsonResponse
    {
        $v = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['nullable', 'in:default,prod'],
        ]);

        $source = $v['source'] ?? 'default';
        if ($source === 'prod' && ! InfoPagi::prodPinned()) {
            return ApiResponse::error('not_configured', 'The prod info source is not configured on this server.', 501);
        }

        try {
            $out = $this->info->briefing($v['date'] ?? InfoPagi::today(), $source);

            return ApiResponse::ok([
                'event' => $out['event'],
                'marketing' => $out['marketing'],
            ], [
                'date' => $out['date'],
                'source' => $out['source'],
                // One section failing must stay visible to the n8n caller
                // instead of looking like an empty briefing (#234).
                'errors' => (object) $out['errors'],
            ]);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
    }
}
