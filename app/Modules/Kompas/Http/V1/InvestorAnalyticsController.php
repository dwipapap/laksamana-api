<?php

namespace App\Modules\Kompas\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Kompas\Services\InvestorAnalytics;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/kompas/investor (module investor; uploading/deleting reports needs
 * its admin) and /api/v1/kompas/analytics (module analytics; the page matrix
 * and roles need its admin). See docs/api/kompas.md.
 *
 * Versions: analytics data = its `updated_at` (If-Match required on PUT);
 * matrix / roles = a content hash. Reports are replaced whole per
 * (month, kind) by an admin, so they carry `at` but need no version.
 */
class InvestorAnalyticsController
{
    public function __construct(
        private readonly InvestorAnalytics $svc,
        private readonly OfficeAccess $access,
    ) {}

    // ─────────────────────────── investor ──

    public function summary(Request $r): JsonResponse
    {
        if ($denied = $this->gate($r, 'investor')) {
            return $denied;
        }
        // a module admin sees every investor, anyone else only their own (server-side filter)
        $id = (string) $r->user()->getKey();
        $admin = $this->isAdmin($r, 'investor');
        $viewer = ['id' => $id, 'name' => (string) ($this->access->userById($id)['name'] ?? '')];

        return ApiResponse::ok($this->svc->summary($viewer, $admin), ['canUpload' => $admin]);
    }

    public function agenda(Request $r): JsonResponse
    {
        return $this->gate($r, 'investor') ?? ApiResponse::ok($this->svc->agenda());
    }

    public function reports(Request $r): JsonResponse
    {
        return $this->gate($r, 'investor') ?? ApiResponse::ok($this->svc->reports());
    }

    public function report(Request $r, string $bulan, string $jenis): Response
    {
        if ($denied = $this->gate($r, 'investor')) {
            return $denied;
        }
        $f = $this->svc->reportFile($bulan, $jenis);

        return is_string($f) ? ApiResponse::error('not_found', $f, 404)
            : response()->file($f['path'], ['Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9._ -]/', '_', $f['nama']).'"',
                'Cache-Control' => 'private, max-age=0, no-store']);
    }

    /** Body {dataBase64, fileName?}: a PDF (checked by content), ≤ 12 MB; replaces that month's report. Admin only. */
    public function putReport(Request $r, string $bulan, string $jenis): JsonResponse
    {
        if ($denied = $this->admin($r, 'investor')) {
            return $denied;
        }
        $out = $this->svc->saveReport($bulan, $jenis, $r->json()->all(), $this->actor($r));
        if (empty($out['ok'])) {
            return ApiResponse::error('invalid_request', $out['error'], 422);
        }
        unset($out['ok']);

        return ApiResponse::ok($out + ['lapor' => $this->svc->reports()]);
    }

    public function deleteReport(Request $r, string $bulan, string $jenis): JsonResponse
    {
        if ($denied = $this->admin($r, 'investor')) {
            return $denied;
        }
        $this->svc->deleteReport($bulan, $jenis);

        return ApiResponse::ok(['deleted' => true, 'lapor' => $this->svc->reports()]);
    }

    // ─────────────────────────── analytics ──

    public function analytics(Request $r): JsonResponse
    {
        if ($denied = $this->gate($r, 'analytics')) {
            return $denied;
        }
        $a = $this->svc->analytics();

        return ApiResponse::ok($a, ['version' => $a['ts'], 'accessVersion' => self::hash([$a['akses'], $a['peran']])]);
    }

    /** Body {data: {laporan, setting, …}} — the whole analytics state; If-Match = its version (ts). */
    public function putAnalytics(Request $r): JsonResponse
    {
        if ($denied = $this->gate($r, 'analytics')) {
            return $denied;
        }
        $v = self::version($r);
        if ($v === null || ! ctype_digit($v)) {
            return self::versionRequired();
        }
        $cur = $this->svc->analytics()['ts'];
        if ((int) $v !== $cur) {
            return ApiResponse::error('version_conflict', 'The analytics data was saved by someone else.', 409, ['version' => $cur]);
        }
        $ts = max((int) (microtime(true) * 1000), $cur + 1);
        $out = $this->svc->saveAnalytics($r->json('data'), $this->actor($r), $ts);

        return empty($out['ok']) ? ApiResponse::error('invalid_request', $out['error'], 422) : ApiResponse::ok(['saved' => true], ['version' => $ts]);
    }

    /** Body {matrix: {role: {page: 0|1|2}}} and/or {roles: {'#<userId>': role}} — each replaces its whole map. Admin; If-Match = accessVersion. */
    public function putAccess(Request $r): JsonResponse
    {
        if ($denied = $this->admin($r, 'analytics')) {
            return $denied;
        }
        $v = self::version($r);
        if ($v === null) {
            return self::versionRequired();
        }
        $a = $this->svc->analytics();
        if (! hash_equals(self::hash([$a['akses'], $a['peran']]), $v)) {
            return ApiResponse::error('version_conflict', 'The access settings were changed by someone else.', 409);
        }
        $body = $r->json()->all();
        foreach (['matrix' => 'saveAnalyticsAccess', 'roles' => 'saveAnalyticsRoles'] as $key => $fn) {
            if (array_key_exists($key, $body)) {
                $out = $this->svc->$fn($body[$key]);
                if (empty($out['ok'])) {
                    return ApiResponse::error('invalid_request', $out['error'], 422);
                }
            }
        }
        $a = $this->svc->analytics();

        return ApiResponse::ok(['matrix' => $a['akses'], 'roles' => $a['peran']], ['accessVersion' => self::hash([$a['akses'], $a['peran']])]);
    }

    // ─────────────────────────── helpers ──

    private function gate(Request $r, string $module): ?JsonResponse
    {
        return $this->access->hasModule((string) $r->user()->getKey(), $module) ? null
            : ApiResponse::error('module_not_granted', "Your account has no access to module [$module].", 403);
    }

    private function admin(Request $r, string $module): ?JsonResponse
    {
        return $this->gate($r, $module) ?? ($this->isAdmin($r, $module) ? null
            : ApiResponse::error('forbidden', "Admin rights for module [$module] required.", 403));
    }

    private function isAdmin(Request $r, string $module): bool
    {
        return $this->access->isModuleAdmin((string) $r->user()->getKey(), $module);
    }

    private function actor(Request $r): string
    {
        $id = (string) $r->user()->getKey();

        return (string) ($this->access->userById($id)['name'] ?? $id);
    }

    private static function hash(mixed $v): string
    {
        return substr(sha1(json_encode($v, JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    private static function version(Request $r): ?string
    {
        $h = $r->header('If-Match');
        $v = is_string($h) && $h !== '' ? trim($h, ' "W/') : $r->query('version');

        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function versionRequired(): JsonResponse
    {
        return ApiResponse::error('version_required', 'Send the version you loaded as If-Match (or ?version=).', 428);
    }
}
