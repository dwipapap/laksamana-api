<?php

namespace App\Modules\Event\Http\V1;

use App\Modules\Event\Services\EventState;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/event/admin/reset {konfirmasi: "KOSONGKAN"} — "KOSONGKAN SEMUA
 * DATA" of the old Pengaturan (resetData, G-13, #187). Module admin only (route).
 * Check-ins are append-only and stay; see EventState::resetAll.
 */
class EventAdminController
{
    public function __construct(private readonly EventState $state) {}

    public function reset(Request $r): JsonResponse
    {
        if (strtoupper(trim((string) $r->json('konfirmasi'))) !== 'KOSONGKAN') {
            return ApiResponse::error('confirmation_required', 'Ketik KOSONGKAN untuk mengosongkan semua data Event.', 422);
        }

        return ApiResponse::ok(['reset' => true] + $this->state->resetAll());
    }
}
