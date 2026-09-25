<?php

namespace App\Modules\Reservasi\Http\V1;

use App\Modules\Reservasi\Services\ReservasiConflict;
use App\Modules\Reservasi\Services\ReservasiRecords;
use App\Modules\Reservasi\Services\ReservasiState;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/reservasi — reservations (per-row version = updatedAt), the master
 * blob shared with Service Excellent (content-hash version), photo files and
 * the audit trail. See docs/api/reservasi.md. Every write bumps the global
 * `_ver` so legacy tabs reload instead of reconciling around it.
 */
class ReservasiController
{
    public function __construct(
        private readonly ReservasiState $state,
        private readonly ReservasiRecords $records,
    ) {}

    public function index(Request $r): JsonResponse
    {
        $f = $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $rows = $this->records->list($f['from'] ?? null, $f['to'] ?? null);

        return ApiResponse::ok($rows, ['total' => count($rows), 'ver' => $this->state->ver()]);
    }

    public function show(string $id): JsonResponse
    {
        $row = $this->records->find($id);

        return $row ? self::withVersion($row) : self::notFound();
    }

    /** Body = the reservation (app fields); `id` optional. Inline `data:` photos are moved to files. */
    public function store(Request $r): JsonResponse
    {
        $row = $r->json()->all();
        unset($row['_audit']);
        if ($row === [] || array_is_list($row)) {
            return ApiResponse::error('validation_failed', 'The body must be a JSON object.', 422);
        }
        $row['id'] = isset($row['id']) && is_string($row['id']) && $row['id'] !== '' ? substr($row['id'], 0, 64) : 'r'.base_convert((string) (int) (microtime(true) * 1000), 10, 36).bin2hex(random_bytes(3));

        return $this->write(fn () => $this->records->put($row, null, false, $this->reservationAudit($r, $row['id'])), 201);
    }

    public function update(Request $r, string $id): JsonResponse
    {
        return $this->replace($r, $id, false);
    }

    public function patch(Request $r, string $id): JsonResponse
    {
        return $this->replace($r, $id, true);
    }

    private function replace(Request $r, string $id, bool $merge): JsonResponse
    {
        $body = $r->json()->all();
        unset($body['_audit']);
        if (isset($body['id']) && (string) $body['id'] !== $id) {
            return ApiResponse::error('validation_failed', 'The id in the body does not match the URL.', 422);
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }
        $cur = $this->records->find($id);
        if (! $cur) {
            return self::notFound();
        }
        $row = ['id' => $id] + ($merge ? array_replace($cur['row'], $body) : $body);

        return $this->write(fn () => $this->records->put($row, (int) $v, true, $this->reservationAudit($r, $id)));
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }

        return $this->write(fn () => $this->records->delete($id, (int) $v, $this->reservationAudit($r, $id)) ? ['deleted' => true] : null);
    }

    public function master(): JsonResponse
    {
        $m = $this->records->master();

        return ApiResponse::ok($m['value'], ['version' => $m['version']], 200, ['ETag' => '"'.$m['version'].'"']);
    }

    /** Body {value: {...}} — the whole master blob (tables, reviews, feedbacks, …). If-Match = its version. */
    public function putMaster(Request $r): JsonResponse
    {
        $v = self::version($r);
        if ($v === null) {
            return self::versionRequired();
        }

        return $this->write(function () use ($r, $v) {
            $m = $this->records->putMaster($r->json('value'), $v);

            return ['__version' => $m['version'], 'value' => $m['value']];
        });
    }

    /** One master section, versioned by its own content so two screens never contend. */
    public function section(Request $r, string $section): JsonResponse
    {
        if ($bad = self::badSection($section)) {
            return $bad;
        }
        $value = $this->records->section($section);
        $version = ReservasiRecords::version($value);

        return ApiResponse::ok($value, ['version' => $version, 'ver' => $this->state->ver()], 200, ['ETag' => '"'.$version.'"']);
    }

    /** Body {value: …}; `null` removes the section. If-Match = that section's version. */
    public function putSection(Request $r, string $section): JsonResponse
    {
        if ($bad = self::badSection($section)) {
            return $bad;
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }
        $body = $r->json()->all();
        if (! array_key_exists('value', $body)) {
            return ApiResponse::error('validation_failed', 'Send {"value": …}.', 422);
        }

        return $this->write(fn () => $this->records->putSection($section, $body['value'], $v));
    }

    /** Replace one review / feedback / waitlist item without touching its neighbours. */
    public function putItem(Request $r, string $section, string $id): JsonResponse
    {
        if ($bad = self::badItemSection($section)) {
            return $bad;
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }
        $value = $r->json('value');
        if (! is_array($value) || array_is_list($value) || ! isset($value['id']) || (string) $value['id'] !== $id) {
            return ApiResponse::error('validation_failed', 'Send {"value": {…,"id":"'.$id.'"}} with the id matching the URL.', 422);
        }

        return $this->write(fn () => $this->records->putItem($section, $id, $value, $v));
    }

    public function deleteItem(Request $r, string $section, string $id): JsonResponse
    {
        if ($bad = self::badItemSection($section)) {
            return $bad;
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }

        return $this->write(fn () => $this->records->deleteItem($section, $id, $v));
    }

    public function audit(): JsonResponse
    {
        return ApiResponse::ok($this->state->readAudit());
    }

    /** An action that belongs to no reservation row (settings, roles, targets, …). */
    public function storeAudit(Request $r): JsonResponse
    {
        return $this->write(function () use ($r) {
            $action = $r->json('action');
            $detail = $r->json('detail', '');
            $res = $r->json('res');
            if (! is_string($action) || trim($action) === '' || ! is_string($detail) || (! is_null($res) && ! is_string($res))) {
                throw new RuntimeException('Send {action, detail?, res?} with a non-empty action.');
            }
            $row = $this->records->logAudit($action, $detail, $this->actor($r), $res === '' ? null : $res);

            return ['__value' => $row, 'ver' => $this->state->ver()];
        }, 201);
    }

    public function file(string $key): JsonResponse
    {
        return ApiResponse::ok($this->state->getFile($key));
    }

    /** Body {data: "data:…"} — empty deletes the file. */
    public function putFile(Request $r, string $key): JsonResponse
    {
        $out = $this->state->putFileV1(['key' => $key, 'data' => (string) $r->json('data', '')]);

        return ApiResponse::ok($out, ['ver' => $this->state->ver()]);
    }

    // ─────────────────────────── helpers ──

    private function write(\Closure $fn, int $status = 200): JsonResponse
    {
        try {
            $out = $fn();
        } catch (ReservasiConflict $e) {
            return match ($e->getMessage()) {
                'exists' => ApiResponse::error('already_exists', 'A reservation with this id already exists.', 409),
                'missing' => self::notFound(),
                default => ApiResponse::error('version_conflict', 'Changed by someone else. Reload and apply your change again.', 409, ['current' => is_array($e->current) && isset($e->current['row']) ? $e->current['row'] : $e->current]),
            };
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
        if ($out === null) {
            return self::notFound();
        }
        if (isset($out['__value'])) {
            return ApiResponse::ok($out['__value'], ['ver' => $out['ver']], $status);
        }
        if (isset($out['__version'])) {
            return ApiResponse::ok($out['value'], ['version' => $out['__version']], $status, ['ETag' => '"'.$out['__version'].'"']);
        }
        if (array_key_exists('value', $out) && isset($out['version'])) {
            return ApiResponse::ok($out['value'], ['version' => $out['version']], $status, ['ETag' => '"'.$out['version'].'"']);
        }

        return isset($out['row']) ? self::withVersion($out, $status) : ApiResponse::ok($out, [], $status);
    }

    /** The acting User always comes from the token, never from the body. */
    private function actor(Request $r): array
    {
        $user = $r->user();

        return ['id' => (string) $user->getKey(), 'name' => (string) $user->name];
    }

    /** Optional `_audit: {action, detail?}` on a reservation write. */
    private function reservationAudit(Request $r, string $res): ?array
    {
        $raw = $r->json('_audit');
        if ($raw === null) {
            return null;
        }
        $action = is_array($raw) ? ($raw['action'] ?? null) : null;
        $detail = is_array($raw) ? ($raw['detail'] ?? '') : '';
        if (! is_string($action) || trim($action) === '' || ! is_string($detail)) {
            throw new RuntimeException('_audit must be {action, detail?} with a non-empty action.');
        }

        return $this->state->auditEntry($action, $detail, $this->actor($r), $res);
    }

    private static function badSection(string $section): ?JsonResponse
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/', $section)
            ? null
            : ApiResponse::error('validation_failed', 'Unknown master section.', 422);
    }

    private static function badItemSection(string $section): ?JsonResponse
    {
        return in_array($section, ReservasiRecords::ITEM_SECTIONS, true)
            ? null
            : ApiResponse::error('not_found', 'Not found.', 404);
    }

    private static function withVersion(array $row, int $status = 200): JsonResponse
    {
        return ApiResponse::ok($row['row'], ['version' => $row['version']], $status, ['ETag' => '"'.$row['version'].'"']);
    }

    private static function version(Request $r): ?string
    {
        $h = $r->header('If-Match');
        $v = is_string($h) && $h !== '' ? trim($h, ' "W/') : $r->query('version');

        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function versionRequired(): JsonResponse
    {
        return ApiResponse::error('version_required', 'Send the version you edited as If-Match (or ?version=).', 428);
    }

    private static function notFound(): JsonResponse
    {
        return ApiResponse::error('not_found', 'Not found.', 404);
    }
}
