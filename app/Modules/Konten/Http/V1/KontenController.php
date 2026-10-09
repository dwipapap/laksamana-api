<?php

namespace App\Modules\Konten\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Konten\Services\KontenFiles;
use App\Modules\Konten\Services\KontenQueries;
use App\Modules\Konten\Services\KontenRecords;
use App\Modules\Konten\Services\KontenState;
use App\Modules\Konten\Services\RecordConflict;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/konten — the full konten contract (see docs/api/konten.md).
 * Everything the laksamana-office konten screens do is reachable here:
 * content planning & pipeline, shooting schedule, design/editing queues,
 * assets, approvals, publishing, performance input, KOL & visits, ads &
 * ad funds, brands, activity log, notifications, crew, and the settings
 * documents (settings, perms, seeded).
 *
 * Concurrency: every record/document has a `version` (ETag). Writes must
 * send it (If-Match header or `version` in the body); a stale one => 409 with
 * the current record in error.details.current.
 */
class KontenController
{
    public function __construct(
        private readonly KontenRecords $records,
        private readonly KontenState $state,
        private readonly KontenQueries $queries,
        private readonly KontenFiles $files,
        private readonly OfficeAccess $access,
    ) {}

    private function actor(Request $r): string
    {
        return (string) ($this->access->userById((string) $r->user()->getKey())['name'] ?? $r->user()->getKey());
    }

    private function baseVersion(Request $r): ?string
    {
        $h = $r->header('If-Match');
        if (is_string($h) && $h !== '') {
            return trim($h, ' "W/');
        }
        $v = $r->input('version');

        return $v === null ? null : (string) $v;
    }

    private static function withVersion(array $row): JsonResponse
    {
        $v = KontenRecords::versionOf($row);

        return ApiResponse::ok($row, ['version' => $v], 200, ['ETag' => '"'.$v.'"']);
    }

    private static function conflict(RecordConflict $e): JsonResponse
    {
        if ($e->getMessage() === 'exists') {
            return ApiResponse::error('already_exists', 'A record with this id already exists.', 409);
        }

        return ApiResponse::error('version_conflict', 'The record was changed by someone else. Reload it and apply your change again.', 409,
            ['current' => $e->current]);
    }

    private function res(string $resource): void
    {
        if (! isset(KontenRecords::RESOURCES[$resource])) {
            abort(404);
        }
    }

    private function safe(callable $fn): JsonResponse
    {
        try {
            return ApiResponse::ok($fn());
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
    }

    // ─────────────────────────── state & diagnostics ──

    /** One-call bootstrap: the full state S (same as legacy getAll). */
    public function state(): JsonResponse
    {
        return ApiResponse::ok($this->state->read());
    }

    public function stats(): JsonResponse
    {
        return $this->safe(fn () => $this->queries->stats());
    }

    /** Narrow brand & crew picker for Marketing's Request Design form (open to both modules). */
    public function designOptions(): JsonResponse
    {
        return ApiResponse::ok($this->queries->designOptions());
    }

    // ─────────────────────────── generic records ──

    public function index(Request $r, string $resource): JsonResponse
    {
        $this->res($resource);
        $f = $r->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string'], 'brand' => ['nullable', 'string'], 'pic' => ['nullable', 'string'],
            'platform' => ['nullable', 'string'], 'campaign' => ['nullable', 'string'], 'kolId' => ['nullable', 'string'],
            'updatedSince' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $page = (int) ($f['page'] ?? 1);
        $per = (int) ($f['perPage'] ?? 100);
        $out = $this->records->list($resource, $f, $page, $per);

        return ApiResponse::ok($out['rows'], ['total' => $out['total'], 'page' => $page, 'perPage' => $per]);
    }

    public function show(string $resource, string $id): JsonResponse
    {
        $this->res($resource);
        $row = $this->records->find($resource, $id);

        return $row ? self::withVersion($row) : ApiResponse::error('not_found', 'Record not found.', 404);
    }

    public function store(Request $r, string $resource): JsonResponse
    {
        $this->res($resource);
        $data = $r->json()->all();
        if (! is_array($data) || array_is_list($data) && $data !== []) {
            return ApiResponse::error('invalid_request', 'Body must be a JSON object (the record).', 422);
        }
        unset($data['version'], $data['updatedAt']);
        try {
            $row = $this->records->create($resource, $data, $this->actor($r))['row'];

            return ApiResponse::created($row, ['version' => KontenRecords::versionOf($row)]);
        } catch (RecordConflict $e) {
            return self::conflict($e);
        }
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
        $this->res($resource);
        $base = $this->baseVersion($r);
        if ($base === null || ! ctype_digit($base)) {
            return ApiResponse::error('version_required', 'Send the version you last read (If-Match header or "version").', 428);
        }
        $data = $r->json()->all();
        unset($data['version'], $data['updatedAt']);
        try {
            $res = $this->records->update($resource, $id, $data, (int) $base, $this->actor($r), $merge);

            return self::withVersion($res['row']);
        } catch (RecordConflict $e) {
            return self::conflict($e);
        } catch (RuntimeException $e) {
            return $e->getMessage() === 'not_found'
                ? ApiResponse::error('not_found', 'Record not found.', 404)
                : throw $e;
        }
    }

    public function destroy(Request $r, string $resource, string $id): JsonResponse
    {
        $this->res($resource);
        $base = $this->baseVersion($r);
        if ($base === null || ! ctype_digit($base)) {
            return ApiResponse::error('version_required', 'Send the version you last read (If-Match header or ?version=).', 428);
        }
        try {
            $this->records->delete($resource, $id, (int) $base, $this->actor($r));

            return ApiResponse::ok(['deleted' => true, 'id' => $id]);
        } catch (RecordConflict $e) {
            return self::conflict($e);
        } catch (RuntimeException $e) {
            return $e->getMessage() === 'not_found'
                ? ApiResponse::error('not_found', 'Record not found.', 404)
                : throw $e;
        }
    }

    // ─────────────────────────── performance (content.data.perf) ──

    /** Merge one platform's figures into a content row: {platform, metrics}. A null metrics removes that platform's entry. */
    public function performance(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['platform' => ['required', 'string', 'max:32'], 'metrics' => ['nullable', 'array']]);
        try {
            $metrics = array_key_exists('metrics', $d) ? $d['metrics'] : [];
            $res = $this->records->setPerformance($id, $d['platform'], $metrics, $this->actor($r));

            return self::withVersion($res['row']);
        } catch (RecordConflict $e) {
            return self::conflict($e);
        } catch (RuntimeException $e) {
            return $e->getMessage() === 'not_found'
                ? ApiResponse::error('not_found', 'Record not found.', 404)
                : ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
    }

    // ─────────────────────────── logs (activity trail) ──

    public function logs(Request $r): JsonResponse
    {
        $d = $r->validate(['refId' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'min:1', 'max:5000']]);
        $out = $this->records->logs($d['refId'] ?? null, (int) ($d['limit'] ?? 200));

        return ApiResponse::ok($out['rows'], ['total' => $out['total']]);
    }

    public function logActivity(Request $r): JsonResponse
    {
        $d = $r->validate(['action' => ['required', 'string', 'max:255'], 'target' => ['nullable', 'string', 'max:255']]);
        $row = $this->records->logActivity($d, $this->actor($r))['row'];

        return ApiResponse::created($row);
    }

    // ─────────────────────────── settings documents ──

    public function document(string $doc): JsonResponse
    {
        if (! in_array($doc, KontenRecords::DOCUMENTS, true)) {
            return ApiResponse::error('not_found', 'Unknown document.', 404);
        }
        $d = $this->records->document($doc);

        return ApiResponse::ok($d['value'], ['version' => $d['version']], 200, ['ETag' => '"'.$d['version'].'"']);
    }

    public function putDocument(Request $r, string $doc): JsonResponse
    {
        if (! in_array($doc, KontenRecords::DOCUMENTS, true)) {
            return ApiResponse::error('not_found', 'Unknown document.', 404);
        }
        $base = $r->header('If-Match') ? trim((string) $r->header('If-Match'), ' "W/') : (string) $r->query('version', '');
        if ($base === '') {
            return ApiResponse::error('version_required', 'Send the version you last read (If-Match header or ?version=).', 428);
        }
        try {
            $d = $this->records->putDocument($doc, $r->json()->all(), $base);

            return ApiResponse::ok($d['value'], ['version' => $d['version']], 200, ['ETag' => '"'.$d['version'].'"']);
        } catch (RecordConflict $e) {
            return self::conflict($e);
        }
    }

    // ─────────────────────────── files ──

    /** JSON {dataBase64,mimeType,fileName} or multipart `file`. Returns {key,name,url}. */
    public function upload(Request $r): JsonResponse
    {
        try {
            if ($r->hasFile('file')) {
                $f = $r->file('file');
                $p = ['dataBase64' => base64_encode((string) file_get_contents($f->getRealPath())),
                    'mimeType' => $f->getMimeType(), 'fileName' => $f->getClientOriginalName()];
            } else {
                $p = $r->json()->all();
            }
            $out = $this->files->save($p);

            return ApiResponse::created($out + ['url' => url('/api/v1/konten/files/'.$out['key'])]);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_file', $e->getMessage(), 422);
        }
    }

    public function file(string $key): Response
    {
        return $this->files->stream($key);
    }
}
