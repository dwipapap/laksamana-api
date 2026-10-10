<?php

namespace App\Modules\Konten\Http\V1;

use App\Modules\Konten\Services\KontenState;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /api/v1/konten/admin/restore {konfirmasi: "PULIHKAN", data: {…backup…}} —
 * "Pulihkan dari backup" of the old Pengaturan (restoreJSON, G-13, #187): the
 * whole database is replaced by the file. Module admin only (route).
 */
class KontenAdminController
{
    public function __construct(private readonly KontenState $state) {}

    public function restore(Request $r): JsonResponse
    {
        if (strtoupper(trim((string) $r->json('konfirmasi'))) !== 'PULIHKAN') {
            return ApiResponse::error('confirmation_required', 'Ketik PULIHKAN untuk mengganti seluruh data dengan berkas backup.', 422);
        }
        try {
            $out = $this->state->restoreAll($r->json('data'));
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_backup', $e->getMessage(), 422);
        }

        return ApiResponse::ok(['restored' => true, 'jumlah' => $out['jumlah'] ?? [], 'bentrok' => $out['bentrok'] ?? []]);
    }
}
