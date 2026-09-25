<?php

namespace App\Modules\Bd\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Bd\Services\BdConflict;
use App\Modules\Bd\Services\BdRecords;
use App\Modules\Bd\Services\BdState;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/bd — BD OS (see docs/api/bd.md): Dashboard, Tasks, Projects,
 * Calendar, Koordinasi, Routine, Promotion, Purchasing (PO + weekly PR +
 * signer sets), Kru BD, daily focus — plus the two cross-module writes
 * (Marketing's PO requests, Finance's realisation).
 *
 * Concurrency: records carry `version` = updated_at (ms); documents a hash.
 * Writes send it as If-Match (or ?version=); stale => 409 with the current value.
 */
class BdController
{
    public function __construct(
        private readonly BdState $state,
        private readonly BdRecords $records,
        private readonly OfficeAccess $access,
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

    public function index(Request $r, string $resource): JsonResponse
    {
        $f = $r->validate(['updatedSince' => ['nullable', 'integer', 'min:0']]);
        $rows = $this->records->list($resource, (int) ($f['updatedSince'] ?? 0));

        return ApiResponse::ok($rows, ['total' => count($rows)]);
    }

    public function show(string $resource, string $id): JsonResponse
    {
        $row = $this->records->find($resource, $id);

        return $row ? self::withVersion($row['row'], $row['version']) : self::notFound();
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
        try {
            $row = $this->records->create($resource, $body);
        } catch (BdConflict) {
            return ApiResponse::error('already_exists', 'A record with this id already exists.', 409);
        }

        return self::withVersion($row['row'], $row['version'], 201);
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
        if (($v = self::baseVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $row = $this->records->update($resource, $id, $body, (int) $v, $merge);
        } catch (BdConflict $e) {
            return self::conflict($e->current['row']);
        }

        return $row ? self::withVersion($row['row'], $row['version']) : self::notFound();
    }

    public function destroy(Request $r, string $resource, string $id): JsonResponse
    {
        if (($v = self::baseVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $ok = $this->records->delete($resource, $id, (int) $v);
        } catch (BdConflict $e) {
            return self::conflict($e->current['row']);
        }

        return $ok ? ApiResponse::ok(['deleted' => true]) : self::notFound();
    }

    // ─────────────────────────── purchasing actions ──

    /** Marketing's purchase requests: insert-only, ids generated server-side (legacy addPo). */
    public function addPurchaseOrders(Request $r): JsonResponse
    {
        $items = self::body($r)['items'] ?? null;
        try {
            $out = $this->state->addPo($items);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }

        return ApiResponse::created(['added' => $out['added'], 'ids' => $out['ids']]);
    }

    /**
     * Finance → Kas Kecil realisation (legacy setRealisasi). Body {realisasi}; ''
     * or null clears it. `oleh` is the session user, never taken from the body.
     */
    public function realisasi(Request $r, string $id): JsonResponse
    {
        $body = self::body($r) ?? [];
        if (! array_key_exists('realisasi', $body)) {
            return ApiResponse::error('validation_failed', 'Send {"realisasi": <amount> | "" | null}.', 422);
        }
        if (! $this->records->find('purchase-orders', $id)) {
            return self::notFound();
        }
        $name = (string) ($this->access->userById((string) $r->user()->getKey())['name'] ?? $r->user()->getKey());
        $out = $this->state->setRealisasi($id, $body['realisasi'], $name);
        $row = $this->records->find('purchase-orders', $id);

        return self::withVersion(['result' => array_diff_key($out, ['ts' => 1]), 'record' => $row['row']], $row['version']);
    }

    // ─────────────────────────── settings documents ──

    public function documents(): JsonResponse
    {
        $data = [];
        $versions = [];
        foreach (array_keys(BdRecords::DOCUMENTS) as $k) {
            $d = $this->records->document($k);
            $data[$k] = $d['value'];
            $versions[$k] = $d['version'];
        }

        return ApiResponse::ok($data, ['versions' => $versions]);
    }

    public function document(string $key): JsonResponse
    {
        $d = $this->records->document($key);

        return self::withVersion($d['value'], $d['version']);
    }

    /** Body: {"value": …}. */
    public function putDocument(Request $r, string $key): JsonResponse
    {
        $body = self::body($r);
        if ($body === null || ! array_key_exists('value', $body)) {
            return ApiResponse::error('validation_failed', 'Send {"value": …}.', 422);
        }
        if (($v = self::baseVersion($r)) === null) {
            return self::versionRequired();
        }
        try {
            $d = $this->records->putDocument($key, $body['value'], $v);
        } catch (BdConflict $e) {
            return self::conflict($e->current['value']);
        }

        return self::withVersion($d['value'], $d['version']);
    }

    // ─────────────────────────── helpers ──

    private static function body(Request $r): ?array
    {
        $b = json_decode((string) $r->getContent(), true);

        return is_array($b) && ($b === [] || ! array_is_list($b)) ? $b : null;
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

    private static function withVersion(mixed $data, int|string $version, int $status = 200): JsonResponse
    {
        return ApiResponse::ok($data, ['version' => $version], $status, ['ETag' => '"'.$version.'"']);
    }

    private static function conflict(mixed $current): JsonResponse
    {
        return ApiResponse::error('version_conflict', 'The record was changed by someone else. Reload it and apply your change again.', 409,
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
