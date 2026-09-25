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
        if ($row === [] || array_is_list($row)) {
            return ApiResponse::error('validation_failed', 'The body must be a JSON object.', 422);
        }
        $row['id'] = isset($row['id']) && is_string($row['id']) && $row['id'] !== '' ? substr($row['id'], 0, 64) : 'r'.base_convert((string) (int) (microtime(true) * 1000), 10, 36).bin2hex(random_bytes(3));

        return $this->write(fn () => $this->records->put($row, null, false), 201);
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

        return $this->write(fn () => $this->records->put($row, (int) $v, true));
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }

        return $this->write(fn () => $this->records->delete($id, (int) $v) ? ['deleted' => true] : null);
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

    public function audit(): JsonResponse
    {
        return ApiResponse::ok($this->state->read()['audit']);
    }

    public function file(string $key): JsonResponse
    {
        return ApiResponse::ok($this->state->getFile($key));
    }

    /** Body {data: "data:…"} — empty deletes the file. */
    public function putFile(Request $r, string $key): JsonResponse
    {
        return ApiResponse::ok($this->state->putFile(['key' => $key, 'data' => (string) $r->json('data', '')]));
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
                default => ApiResponse::error('version_conflict', 'Changed by someone else. Reload and apply your change again.', 409, ['current' => $e->current['row'] ?? $e->current]),
            };
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
        if ($out === null) {
            return self::notFound();
        }
        if (isset($out['__version'])) {
            return ApiResponse::ok($out['value'], ['version' => $out['__version']]);
        }

        return isset($out['row']) ? self::withVersion($out, $status) : ApiResponse::ok($out, [], $status);
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
