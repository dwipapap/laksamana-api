<?php

namespace App\Modules\Hlife\Http\V1;

use App\Modules\Hlife\Services\HlifeState;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/hlife/admin/reset — "Reset data" of the old Pengaturan (G-13, #187).
 * Module admin only (route); typed confirmation `KOSONGKAN` in the body.
 */
class HlifeAdminController
{
    public function __construct(private readonly HlifeState $state) {}

    public function reset(Request $r): JsonResponse
    {
        if (strtoupper(trim((string) $r->json('konfirmasi'))) !== 'KOSONGKAN') {
            return ApiResponse::error('confirmation_required', 'Ketik KOSONGKAN untuk mengosongkan semua data.', 422);
        }
        $out = $this->state->resetAll();
        if (empty($out['ok'])) {
            return ApiResponse::error('invalid_request', (string) ($out['error'] ?? 'gagal'), 422);
        }

        return ApiResponse::ok(['reset' => true, 'state' => $this->state->read()]);
    }
}
