<?php

namespace App\Modules\Hr\Http\V1;

use App\Modules\Hr\Services\HrState;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whole-document admin operations of the old Pengaturan (G-13, #187), module
 * admin only (route), each with a typed confirmation and one audit row:
 *
 *   POST /api/v1/hr/admin/import  {konfirmasi: "IMPOR", data: {…backup…}}
 *   POST /api/v1/hr/admin/reset   {konfirmasi: "RESET"}  — back to the seed data
 *
 * Both replace the whole state through saveAll (rev bumped), like the old page.
 */
class HrAdminController
{
    public function __construct(private readonly HrState $state) {}

    public function import(Request $r): JsonResponse
    {
        if (strtoupper(trim((string) $r->json('konfirmasi'))) !== 'IMPOR') {
            return self::confirm('IMPOR');
        }
        // decoded from the raw body so {} stays an object (kpiActuals, attendance, …)
        $body = json_decode($r->getContent());
        $u = $r->user();

        return self::result($this->state->importAll($body->data ?? null, (string) $u->getKey(), (string) $u->name));
    }

    public function reset(Request $r): JsonResponse
    {
        if (strtoupper(trim((string) $r->json('konfirmasi'))) !== 'RESET') {
            return self::confirm('RESET');
        }
        $u = $r->user();

        return self::result($this->state->resetAll((string) $u->getKey(), (string) $u->name));
    }

    private static function confirm(string $word): JsonResponse
    {
        return ApiResponse::error('confirmation_required', "Ketik $word untuk melanjutkan.", 422);
    }

    private static function result(array $out): JsonResponse
    {
        if (! empty($out['ok'])) {
            return ApiResponse::ok(['rev' => $out['rev']], ['version' => $out['rev']], 200, ['ETag' => '"'.$out['rev'].'"']);
        }

        return match ($out['error'] ?? '') {
            'format', 'payload_kosong' => ApiResponse::error('invalid_backup', 'Berkas backup tidak valid: butuh employees dan settings.', 422),
            'conflict' => ApiResponse::error('version_conflict', 'Data HR baru saja disimpan orang lain. Coba lagi.', 409,
                ['savedBy' => $out['savedBy'] ?? '', 'savedAt' => $out['savedAt'] ?? '', 'rev' => $out['rev'] ?? 0]),
            default => ApiResponse::error('invalid_request', (string) ($out['error'] ?? 'gagal'), 422),
        };
    }
}
