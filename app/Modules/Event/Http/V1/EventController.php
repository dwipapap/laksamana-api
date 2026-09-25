<?php

namespace App\Modules\Event\Http\V1;

use App\Modules\Event\Services\EventConflict;
use App\Modules\Event\Services\EventFiles;
use App\Modules\Event\Services\EventRecords;
use App\Modules\Event\Services\EventSchema;
use App\Modules\Event\Services\EventState;
use App\Support\Api\ApiResponse;
use App\Support\RowSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/event — Event Planner / EMS (see docs/api/event.md).
 *
 * Every Panel screen (Dashboard, Pipeline, Approval, Calendar, Talents,
 * Schedule, Fee & Pembayaran, Planning, Events, Ticketing, Check-In, Idea
 * Bank, History, Report, Performa) reads the state or one collection and
 * writes one record below; unlike legacy saveAll a write touches only the
 * record it names.
 *
 * Concurrency: records carry `version` = their updated_at (ms); settings
 * documents a content hash. Writes send it as If-Match (or ?version=);
 * stale => 409 with the current value. Bodies are decoded from the raw JSON
 * (no empty-string-to-null or trimming), exactly as the app stores them.
 */
class EventController
{
    public function __construct(
        private readonly EventState $state,
        private readonly EventRecords $records,
        private readonly EventFiles $files,
    ) {}

    public function state(): JsonResponse
    {
        return ApiResponse::ok($this->state->read());
    }

    public function stats(): JsonResponse
    {
        return ApiResponse::ok($this->state->stats());
    }

    /** eventsHari: the events of one date that actually happen (Finance > Omset). */
    public function eventsOn(string $date): JsonResponse
    {
        if (RowSync::tanggal($date) === null || $date !== trim($date)) {
            return ApiResponse::error('validation_failed', 'The date must be YYYY-MM-DD.', 422);
        }

        return ApiResponse::ok($this->state->eventsHari($date)['events']);
    }

    // ─────────────────────────── records ──

    public function index(Request $r, string $resource): JsonResponse
    {
        $rows = $this->records->list($resource, $r->query());

        return ApiResponse::ok(array_column($rows, 'record'), [
            'total' => count($rows),
            'versions' => (object) array_combine(
                array_map(fn ($x) => (string) ($x['record']['id'] ?? ''), $rows),
                array_column($rows, 'version'),
            ),
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
        if ($body === null) {
            return self::badBody();
        }
        if (isset($body['id']) && (! is_string($body['id']) || strlen($body['id']) > 64)) {
            return ApiResponse::error('validation_failed', 'id must be a string of at most 64 characters.', 422);
        }
        $user = $r->user();
        try {
            $row = $this->records->create($resource, $body, ['id' => (string) $user->getKey(), 'name' => (string) $user->name]);
        } catch (EventConflict $e) {
            return self::conflictFor($e);
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
        if ($body === null) {
            return self::badBody();
        }
        if (isset($body['id']) && (string) $body['id'] !== $id) {
            return ApiResponse::error('validation_failed', 'The id in the body does not match the URL.', 422);
        }
        if (($v = self::intVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $row = $this->records->update($resource, $id, $body, $v, $merge);
        } catch (EventConflict $e) {
            return self::conflictFor($e);
        }

        return $row ? self::withVersion($row['record'], $row['version']) : self::notFound();
    }

    public function destroy(Request $r, string $resource, string $id): JsonResponse
    {
        if (($v = self::intVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $ok = $this->records->delete($resource, $id, $v);
        } catch (EventConflict $e) {
            return self::conflictFor($e);
        }

        return $ok ? ApiResponse::ok(['deleted' => true]) : self::notFound();
    }

    // ─────────────────────────── event details ──

    public function details(): JsonResponse
    {
        $all = $this->records->details();

        return ApiResponse::ok(
            (object) array_map(fn ($d) => $d['record'], $all),
            ['versions' => (object) array_map(fn ($d) => $d['version'], $all)],
        );
    }

    public function detail(string $eventId): JsonResponse
    {
        $d = $this->records->detail($eventId);

        return $d ? self::withVersion($d['record'], $d['version']) : self::notFound();
    }

    /** PUT creates the detail when there is none (no version) or replaces it (If-Match required). */
    public function putDetail(Request $r, string $eventId): JsonResponse
    {
        $body = self::body($r);
        if ($body === null) {
            return self::badBody();
        }
        try {
            $d = $this->records->putDetail($eventId, $body, self::intVersion($r));
        } catch (EventConflict $e) {
            return self::conflictFor($e);
        }

        return self::withVersion($d['record'], $d['version'], $d['created'] ? 201 : 200);
    }

    public function deleteDetail(Request $r, string $eventId): JsonResponse
    {
        if (($v = self::intVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $ok = $this->records->deleteDetail($eventId, $v);
        } catch (EventConflict $e) {
            return self::conflictFor($e);
        }

        return $ok ? ApiResponse::ok(['deleted' => true]) : self::notFound();
    }

    // ─────────────────────────── check-ins ──

    public function checkins(Request $r): JsonResponse
    {
        $limit = ctype_digit((string) $r->query('limit', '')) ? (int) $r->query('limit') : EventState::CHECKIN_LIMIT;
        $rows = $this->records->checkins($r->query(), $limit);

        return ApiResponse::ok($rows, ['total' => count($rows)]);
    }

    public function addCheckin(Request $r): JsonResponse
    {
        $body = self::body($r);
        if ($body === null) {
            return self::badBody();
        }
        if (! isset($body['ticket_id']) || ! is_string($body['ticket_id']) || $body['ticket_id'] === '') {
            return ApiResponse::error('validation_failed', 'ticket_id is required.', 422);
        }
        try {
            $ci = $this->records->addCheckin($body, (string) $r->user()->name);
        } catch (EventConflict $e) {
            return self::conflictFor($e);
        }

        return ApiResponse::ok($ci, [], 201);
    }

    // ─────────────────────────── settings documents ──

    public function settings(): JsonResponse
    {
        $out = $versions = [];
        foreach (array_keys(EventSchema::SETTINGS) as $k) {
            $s = $this->records->setting($k);
            $out[$k] = $s['value'];
            $versions[$k] = $s['version'];
        }

        return ApiResponse::ok($out, ['versions' => $versions]);
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
        if ($body === null || ! array_key_exists('value', $body) || $body['value'] === null) {
            return ApiResponse::error('validation_failed', 'Send {"value": …} (not null).', 422);
        }
        $v = self::rawVersion($r);
        if ($v === null) {
            return self::versionRequired();
        }
        try {
            $s = $this->records->putSetting($key, $body['value'], $v);
        } catch (EventConflict $e) {
            return self::conflictFor($e);
        }

        return self::withVersion($s['value'], $s['version']);
    }

    // ─────────────────────────── files ──

    /** Body: {dataBase64, fileName, mimeType} -> {key, name, size, at}. */
    public function upload(Request $r): JsonResponse
    {
        $body = self::body($r);
        if ($body === null) {
            return self::badBody();
        }
        try {
            return ApiResponse::ok($this->files->save($body), [], 201);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_file', $e->getMessage(), 422);
        }
    }

    public function file(string $key): Response
    {
        $res = $this->files->stream($key);

        return $res->getStatusCode() === 200 ? $res : self::notFound();
    }

    // ─────────────────────────── helpers ──

    private static function body(Request $r): ?array
    {
        $b = json_decode((string) $r->getContent(), true);

        return is_array($b) && ! array_is_list($b) || $b === [] ? $b : null;
    }

    private static function rawVersion(Request $r): ?string
    {
        $h = $r->header('If-Match');
        if (is_string($h) && $h !== '') {
            return trim($h, ' "W/');
        }
        $q = $r->query('version');

        return is_string($q) && $q !== '' ? $q : null;
    }

    private static function intVersion(Request $r): ?int
    {
        $v = self::rawVersion($r);

        return $v !== null && ctype_digit($v) ? (int) $v : null;
    }

    private static function withVersion(mixed $data, int|string $version, int $status = 200): JsonResponse
    {
        return ApiResponse::ok($data, ['version' => $version], $status, ['ETag' => '"'.$version.'"']);
    }

    private static function conflictFor(EventConflict $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'exists' => ApiResponse::error('already_exists', 'A record with this id already exists.', 409),
            'duplicate' => ApiResponse::error('duplicate', 'A unique field (e.g. the ticket QR token) is already used by another record.', 409),
            'version_required' => self::versionRequired(),
            default => ApiResponse::error('version_conflict', 'The record was changed elsewhere. Reload it and apply your change again.', 409,
                ['current' => $e->current]),
        };
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
