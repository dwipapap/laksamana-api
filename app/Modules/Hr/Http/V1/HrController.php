<?php

namespace App\Modules\Hr\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Akademi\Services\AkademiStats;
use App\Modules\Hr\Services\HrMiss;
use App\Modules\Hr\Services\HrRecords;
use App\Modules\Hr\Services\HrState;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * /api/v1/hr — Staff Performance (see docs/api/hr.md). Screens: Dashboard,
 * Karyawan, KPI, OKR, Review, Kompetensi, Training, Coaching, Reward,
 * Disiplin, Engagement, Karier, Kalender, Kehadiran, Skor, Pengaturan.
 *
 * Concurrency: ONE version for the whole document = `meta.rev`, the same
 * guard legacy saveAll uses. Every write sends it (If-Match or ?version=);
 * a stale one => 409 with {rev, savedBy, savedAt}. A successful write returns
 * the new rev in meta.version. saved_by is always the session user.
 *
 * Employee rows carry personal data and PINs: this controller never logs bodies.
 */
class HrController
{
    public function __construct(
        private readonly HrState $state,
        private readonly HrRecords $records,
        private readonly OfficeAccess $access,
        private readonly AkademiStats $akademi,
    ) {}

    public function state(): JsonResponse
    {
        $s = $this->state->read();

        return ApiResponse::ok($s, ['version' => $s['_rev']]);
    }

    public function stats(): JsonResponse
    {
        return ApiResponse::ok($this->state->stats());
    }

    /** Akademi training completion per Office user id (akademi trainingStats, read in-process). */
    public function trainingStats(): JsonResponse
    {
        return ApiResponse::ok($this->akademi->trainingStats());
    }

    // ─────────────────────────── collections ──

    public function index(string $resource): JsonResponse
    {
        $rows = $this->state->records(HrRecords::table($resource));

        return ApiResponse::ok($rows, ['total' => count($rows), 'version' => $this->records->rev()]);
    }

    public function show(string $resource, string $id): JsonResponse
    {
        $row = $this->records->find($resource, $id);

        return $row ? ApiResponse::ok($row, ['version' => $this->records->rev()]) : self::notFound();
    }

    public function store(Request $r, string $resource): JsonResponse
    {
        return $this->putRecord($r, $resource, null, false);
    }

    public function update(Request $r, string $resource, string $id): JsonResponse
    {
        return $this->putRecord($r, $resource, $id, true);
    }

    public function patch(Request $r, string $resource, string $id): JsonResponse
    {
        if ($denied = $this->opsOnly($r, $resource)) {
            return $denied;
        }
        $body = self::body($r);
        if (! $body) {
            return self::badBody();
        }
        $cur = $this->records->find($resource, $id);
        if (! $cur) {
            return self::notFound();
        }
        foreach ($body as $k => $v) {
            $cur->$k = $v;
        }

        return $this->putRecord($r, $resource, $id, true, $cur);
    }

    private function putRecord(Request $r, string $resource, ?string $id, bool $mustExist, ?stdClass $rec = null): JsonResponse
    {
        if ($denied = $this->opsOnly($r, $resource)) {
            return $denied;
        }
        $rec ??= self::body($r);
        if (! $rec) {
            return self::badBody();
        }
        if ($id !== null) {
            if (isset($rec->id) && (string) $rec->id !== $id) {
                return ApiResponse::error('validation_failed', 'The id in the body does not match the URL.', 422);
            }
            $rec->id = $id;
        } elseif (! isset($rec->id) || $rec->id === '') {
            $rec = (object) (['id' => self::newId($resource)] + (array) $rec);
        } elseif (! is_string($rec->id) || strlen($rec->id) > 64) {
            return ApiResponse::error('validation_failed', 'id must be a string of at most 64 characters.', 422);
        }
        if (($base = self::baseVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $res = $this->records->put($resource, $rec, $base, $this->actor($r), $mustExist);
        } catch (HrMiss $e) {
            return $e->getMessage() === 'exists'
                ? ApiResponse::error('already_exists', 'A record with this id already exists.', 409)
                : self::notFound();
        }

        return $this->written($res, $this->records->find($resource, (string) $rec->id), $id === null ? 201 : 200);
    }

    public function destroy(Request $r, string $resource, string $id): JsonResponse
    {
        if ($denied = $this->opsOnly($r, $resource)) {
            return $denied;
        }
        if (($base = self::baseVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $res = $this->records->delete($resource, $id, $base, $this->actor($r));
        } catch (HrMiss) {
            return self::notFound();
        }

        return $this->written($res, ['deleted' => true]);
    }

    // ─────────────────────────── maps ──

    public function kpiActuals(): JsonResponse
    {
        return ApiResponse::ok($this->state->kpiActuals(), ['version' => $this->records->rev()]);
    }

    /** Body {value: number|null}. */
    public function putKpiActual(Request $r, string $divId, string $month, string $itemId): JsonResponse
    {
        return $this->withValue($r, fn ($v, $base, $by) => $this->records->putKpiActual($divId, $month, $itemId, $v, $base, $by),
            fn () => $this->state->kpiActuals()->$divId->$month ?? new stdClass);
    }

    public function monthlyInputs(): JsonResponse
    {
        return ApiResponse::ok($this->state->monthlyInputs(), ['version' => $this->records->rev()]);
    }

    /** Body {value: {...}|null}. */
    public function putMonthly(Request $r, string $empId, string $month): JsonResponse
    {
        return $this->withValue($r, fn ($v, $base, $by) => $this->records->putMonthly($empId, $month, $v, $base, $by),
            fn () => $this->state->monthlyInputs()->$empId->$month ?? null);
    }

    public function settings(): JsonResponse
    {
        return ApiResponse::ok($this->state->read()['settings'], ['version' => $this->records->rev()]);
    }

    /** Body {value: any|null}. */
    public function putSetting(Request $r, string $key): JsonResponse
    {
        return $this->withValue($r, fn ($v, $base, $by) => $this->records->putSetting($key, $v, $base, $by),
            fn () => $this->state->read()['settings']->$key ?? null);
    }

    public function attendance(): JsonResponse
    {
        return ApiResponse::ok($this->state->attendance(), ['version' => $this->records->rev(), 'storage' => $this->state->attendanceTables() ? 'tables' : 'settings']);
    }

    /** Body {value: {fileName, importedAt, importedBy, unmatched, days[]}|null}. */
    public function putAttendance(Request $r, string $month): JsonResponse
    {
        $body = self::body($r);
        if ($body && property_exists($body, 'value') && $body->value !== null && ! $body->value instanceof stdClass) {
            return ApiResponse::error('validation_failed', 'value must be an object or null.', 422);
        }

        return $this->withValue($r, fn ($v, $base, $by) => $this->records->putAttendanceMonth($month, $v, $base, $by),
            fn () => $this->state->attendance()->$month ?? null);
    }

    // ─────────────────────────── audit ──

    public function audit(): JsonResponse
    {
        return ApiResponse::ok($this->state->read()['audit']);
    }

    /** Append one entry; the actor is the session user. Append-only: no version needed. */
    public function appendAudit(Request $r): JsonResponse
    {
        $body = self::body($r);
        if (! $body || ! isset($body->action) || ! is_string($body->action) || $body->action === '') {
            return ApiResponse::error('validation_failed', 'Send {"action": "...", "detail": "..."}.', 422);
        }
        $user = $r->user();
        $entry = (object) [
            'id' => 'a_'.self::rand(11),
            'at' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'userId' => (string) $user->getKey(),
            'userName' => $this->actor($r),
            'action' => $body->action,
            'detail' => is_scalar($body->detail ?? null) ? (string) $body->detail : '',
        ];
        $this->records->appendAudit([$entry]);

        return ApiResponse::created($entry);
    }

    // ─────────────────────────── helpers ──

    private function withValue(Request $r, \Closure $write, \Closure $reread): JsonResponse
    {
        $body = self::body($r);
        if (! $body || ! property_exists($body, 'value')) {
            return ApiResponse::error('validation_failed', 'Send {"value": …}.', 422);
        }
        if (($base = self::baseVersion($r)) === null) {
            return self::versionRequired();
        }

        return $this->written($write($body->value, $base, $this->actor($r)), fn () => $reread());
    }

    /** Map the rev-guard result to the v1 envelope. */
    private function written(array $res, mixed $data, int $status = 200): JsonResponse
    {
        if (empty($res['ok'])) {
            return ApiResponse::error('version_conflict', 'The HR document was saved by someone else. Reload it and apply your change again.', 409,
                ['rev' => $res['rev'], 'savedBy' => $res['savedBy'], 'savedAt' => $res['savedAt']]);
        }

        return ApiResponse::ok($data instanceof \Closure ? $data() : $data, ['version' => $res['rev']], $status, ['ETag' => '"'.$res['rev'].'"']);
    }

    /** Kru and Kalender HR are `manageOps` pages: writes need the Office hr module admin. */
    private function opsOnly(Request $r, string $resource): ?JsonResponse
    {
        if (in_array($resource, ['employees', 'calendar'], true) && ! $this->access->isModuleAdmin((string) $r->user()->getKey(), 'hr')) {
            return ApiResponse::error('forbidden', 'Admin rights for module [hr] required.', 403);
        }

        return null;
    }

    private function actor(Request $r): string
    {
        return (string) ($this->access->userById((string) $r->user()->getKey())['name'] ?? $r->user()->getKey());
    }

    private static function body(Request $r): ?stdClass
    {
        $b = json_decode((string) $r->getContent());

        return $b instanceof stdClass ? $b : null;
    }

    private static function baseVersion(Request $r): ?int
    {
        $h = $r->header('If-Match');
        $v = is_string($h) && $h !== '' ? trim($h, ' "W/') : $r->query('version');

        return is_string($v) && ctype_digit($v) ? (int) $v : null;
    }

    private static function newId(string $resource): string
    {
        return substr(str_replace('-', '', $resource), 0, 3).'_'.self::rand(10);
    }

    private static function rand(int $n): string
    {
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $s .= '0123456789abcdefghijklmnopqrstuvwxyz'[random_int(0, 35)];
        }

        return $s;
    }

    private static function versionRequired(): JsonResponse
    {
        return ApiResponse::error('version_required', 'Send the document rev you edited as If-Match (or ?version=).', 428);
    }

    private static function notFound(): JsonResponse
    {
        return ApiResponse::error('not_found', 'Record not found.', 404);
    }

    private static function badBody(): JsonResponse
    {
        return ApiResponse::error('validation_failed', 'The body must be a JSON object.', 422);
    }
}
