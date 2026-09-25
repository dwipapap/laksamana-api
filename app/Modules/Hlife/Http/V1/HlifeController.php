<?php

namespace App\Modules\Hlife\Http\V1;

use App\Modules\Hlife\Services\HlifeConflict;
use App\Modules\Hlife\Services\HlifeRecords;
use App\Modules\Hlife\Services\HlifeState;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * /api/v1/hlife — Howandi Life OS (see docs/api/hlife.md).
 *
 * Every screen (Command Center, Calendar, Tasks, Projects, Content, Goals,
 * Roadmap & Dreams, Learning, Evaluasi Diri, Finance & Aset, Businesses,
 * Data/Sync/Login) edits one of the collections or settings below; unlike
 * legacy saveAll, a write here only touches the record it names.
 *
 * Concurrency: each record/setting has a `version` (ETag). Writes must send it
 * as If-Match (or ?version=); stale => 409 with the current value.
 * Request bodies are decoded keeping `{}` as an object.
 */
class HlifeController
{
    public function __construct(
        private readonly HlifeState $state,
        private readonly HlifeRecords $records,
    ) {}

    public function state(): JsonResponse
    {
        return ApiResponse::ok($this->state->read());
    }

    public function stats(): JsonResponse
    {
        return ApiResponse::ok($this->state->stats());
    }

    // ─────────────────────────── records ──

    public function index(string $resource): JsonResponse
    {
        $rows = $this->records->list($resource);

        return ApiResponse::ok(array_column($rows, 'record'), [
            'total' => count($rows),
            'versions' => (object) array_combine(array_map(fn ($r) => (string) $r['record']->id, $rows), array_column($rows, 'version')),
        ]);
    }

    public function show(string $resource, string $id): JsonResponse
    {
        $row = $this->records->find($resource, $id);

        return $row ? self::withVersion($row['record'], $row['version']) : self::notFound();
    }

    public function store(Request $r, string $resource): JsonResponse
    {
        $body = self::body($r);
        if (! $body) {
            return self::badBody();
        }
        if (isset($body->id) && (! is_string($body->id) || strlen($body->id) > 64)) {
            return ApiResponse::error('validation_failed', 'id must be a string of at most 64 characters.', 422);
        }
        try {
            $row = $this->records->create($resource, $body);
        } catch (HlifeConflict) {
            return ApiResponse::error('already_exists', 'A record with this id already exists.', 409);
        }

        return self::withVersion($row['record'], $row['version'], 201);
    }

    public function update(Request $r, string $resource, string $id): JsonResponse
    {
        return $this->write($r, $resource, $id, false);
    }

    public function patch(Request $r, string $resource, string $id): JsonResponse
    {
        return $this->write($r, $resource, $id, true);
    }

    private function write(Request $r, string $resource, string $id, bool $merge): JsonResponse
    {
        $body = self::body($r);
        if (! $body) {
            return self::badBody();
        }
        if (isset($body->id) && (string) $body->id !== $id) {
            return ApiResponse::error('validation_failed', 'The id in the body does not match the URL.', 422);
        }
        if (($v = self::baseVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $row = $this->records->update($resource, $id, $body, $v, $merge);
        } catch (HlifeConflict $e) {
            return self::conflict($e->current['record']);
        }

        return $row ? self::withVersion($row['record'], $row['version']) : self::notFound();
    }

    public function destroy(Request $r, string $resource, string $id): JsonResponse
    {
        if (($v = self::baseVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $ok = $this->records->delete($resource, $id, $v);
        } catch (HlifeConflict $e) {
            return self::conflict($e->current['record']);
        }

        return $ok ? ApiResponse::ok(['deleted' => true]) : self::notFound();
    }

    // ─────────────────────────── settings ──

    public function settings(): JsonResponse
    {
        $all = $this->records->settings();

        return ApiResponse::ok(
            (object) array_map(fn ($s) => $s['value'], $all),
            ['versions' => array_map(fn ($s) => $s['version'], $all)],
        );
    }

    public function setting(string $key): JsonResponse
    {
        $s = $this->records->setting($key);

        return self::withVersion($s['value'], $s['version']);
    }

    /** Body: {"value": <any JSON>}. */
    public function putSetting(Request $r, string $key): JsonResponse
    {
        $body = self::body($r);
        if (! $body || ! property_exists($body, 'value')) {
            return ApiResponse::error('validation_failed', 'Send {"value": …}.', 422);
        }
        if (($v = self::baseVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $s = $this->records->putSetting($key, $body->value, $v);
        } catch (HlifeConflict $e) {
            return self::conflict($e->current['value']);
        }

        return self::withVersion($s['value'], $s['version']);
    }

    // ─────────────────────────── helpers ──

    private static function body(Request $r): ?stdClass
    {
        $b = json_decode((string) $r->getContent());

        return $b instanceof stdClass ? $b : null;
    }

    private static function baseVersion(Request $r): ?string
    {
        $h = $r->header('If-Match');
        if (is_string($h) && $h !== '') {
            return trim($h, ' "W/');
        }
        $q = $r->query('version');

        return is_string($q) && $q !== '' ? $q : null;
    }

    private static function withVersion(mixed $data, string $version, int $status = 200): JsonResponse
    {
        return ApiResponse::ok($data, ['version' => $version], $status, ['ETag' => '"'.$version.'"']);
    }

    private static function conflict(mixed $current): JsonResponse
    {
        return ApiResponse::error('version_conflict', 'The record was changed elsewhere. Reload it and apply your change again.', 409,
            ['current' => $current]);
    }

    private static function versionRequired(): JsonResponse
    {
        return ApiResponse::error('version_required', 'Send the version you edited as If-Match (or ?version=).', 428);
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
