<?php

namespace App\Modules\Account\Http\V1;

use App\Modules\Account\Services\BadgesService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/badges — the red menu counts of the Beranda
 * "Perlu tindakan" list in one call ({modul: {layar: n}}).
 * See docs/api/account.md. Authenticated only; each module appears only
 * when the caller may open it. READ-ONLY.
 */
class BadgesController
{
    public function __construct(private readonly BadgesService $badges) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->badges->forUser((string) $request->user()->getKey()));
    }
}
