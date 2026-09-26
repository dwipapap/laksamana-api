<?php

namespace App\Modules\Marketing\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Marketing\Services\MarketingFiles;
use App\Modules\Marketing\Services\MarketingQueries;
use App\Modules\Marketing\Services\MarketingRecords;
use App\Modules\Marketing\Services\MarketingSchema;
use App\Modules\Marketing\Services\MarketingState;
use App\Modules\Marketing\Services\RecordConflict;
use App\Support\Api\ApiResponse;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/marketing — the full marketing contract (see docs/api/marketing.md).
 * Everything the laksamana-office marketing screens do is reachable here:
 * CRM, pipeline/events (brief, quotation, tasks, payments live inside the
 * event record), Reservasi VIP, Request Design, templates, approvals,
 * timeline, notifications, staff/users, and the settings documents
 * (settings, rolePerms, roleNav, menuDb, katalog, …).
 *
 * Concurrency: every record/document has a `version` (ETag). Writes must
 * send it (If-Match header or `version` in the body); a stale one => 409 with
 * the current record in error.details.current.
 */
class MarketingController
{
    public function __construct(
        private readonly MarketingRecords $records,
        private readonly MarketingState $state,
        private readonly MarketingQueries $queries,
        private readonly MarketingFiles $files,
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
        $v = MarketingRecords::versionOf($row);

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
        if (! isset(MarketingRecords::RESOURCES[$resource])) {
            abort(404);
        }
    }

    // ─────────────────────────── state & read models ──

    /** One-call bootstrap: the full state S incl. `_versi` (same as legacy getAll). */
    public function state(): JsonResponse
    {
        $s = $this->state->read();

        return ApiResponse::ok($s, ['version' => $s['_versi']]);
    }

    public function eventsOn(string $date): JsonResponse
    {
        return $this->safe(fn () => $this->queries->eventsOn($date));
    }

    public function dp(Request $r): JsonResponse
    {
        $d = $r->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);

        return $this->safe(fn () => $this->queries->dpIn($d['from'], $d['to']));
    }

    public function designQueue(Request $r): JsonResponse
    {
        return ApiResponse::ok($this->queries->designRequests($r->boolean('active')));
    }

    public function designProgress(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['status' => ['required', 'in:done,todo'], 'picNama' => ['nullable', 'string', 'max:120']]);

        return $this->safe(fn () => $this->queries->setDesignStatus($id, $d['status'], $d['picNama'] ?? $this->actor($r)));
    }

    public function designOptions(Request $r): JsonResponse
    {
        return $this->safe(fn () => $this->queries->setDesignOptions($r->json()->all()));
    }

    private function safe(callable $fn): JsonResponse
    {
        try {
            return ApiResponse::ok($fn());
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
    }

    // ─────────────────────────── generic records ──

    public function index(Request $r, string $resource): JsonResponse
    {
        $this->res($resource);
        $f = $r->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string'], 'pic' => ['nullable', 'string'], 'clientId' => ['nullable', 'string'],
            'eventId' => ['nullable', 'string'], 'updatedSince' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100'],
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

            return ApiResponse::created($row, ['version' => MarketingRecords::versionOf($row)]);
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
            return ApiResponse::error('version_required', 'Send the version you last read (If-Match header or "version").', 428);
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

    // ─────────────────────────── activities (timeline) ──

    public function activities(Request $r): JsonResponse
    {
        $d = $r->validate(['refId' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'min:1', 'max:5000']]);
        $args = [];
        $t = MarketingSchema::table('activities');
        $id = MarketingSchema::idCol();
        $sql = "SELECT data FROM `$t`";
        if (! empty($d['refId'])) {
            $sql .= ' WHERE ref_id = ?';
            $args[] = $d['refId'];
        }
        $sql .= " ORDER BY at_time DESC, `$id` DESC LIMIT ".(int) ($d['limit'] ?? 200);
        $rows = array_values(array_filter(array_map(fn ($x) => json_decode((string) $x->data, true),
            Modules::db('marketing')->select($sql, $args)), 'is_array'));

        return ApiResponse::ok($rows);
    }

    public function logActivity(Request $r): JsonResponse
    {
        $d = $r->validate(['refType' => ['required', 'string', 'max:40'], 'refId' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', 'max:255'], 'detail' => ['nullable', 'string', 'max:2000']]);
        $row = $d + ['id' => 'act_'.strtolower(Str::random(10)), 'by' => $this->actor($r), 'at' => gmdate('Y-m-d\TH:i:s.v\Z')];
        $this->state->appendActivities(Modules::db('marketing'), [$row]);

        return ApiResponse::created($row);
    }

    // ─────────────────────────── settings documents ──

    public function document(string $doc): JsonResponse
    {
        if (! in_array($doc, MarketingRecords::DOCUMENTS, true)) {
            return ApiResponse::error('not_found', 'Unknown document.', 404);
        }
        $d = $this->records->document($doc);

        return ApiResponse::ok($d['value'], ['version' => $d['version']], 200, ['ETag' => '"'.$d['version'].'"']);
    }

    public function putDocument(Request $r, string $doc): JsonResponse
    {
        if (! in_array($doc, MarketingRecords::DOCUMENTS, true)) {
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

            return ApiResponse::created($out + ['url' => url('/api/v1/marketing/files/'.$out['key'])]);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_file', $e->getMessage(), 422);
        }
    }

    /** Chunked upload {uploadId, seq, last, dataBase64, mimeType, fileName}. */
    public function uploadChunk(Request $r): JsonResponse
    {
        try {
            return ApiResponse::ok($this->files->saveChunk($r->json()->all()));
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_file', $e->getMessage(), 422);
        }
    }

    public function file(string $key): Response
    {
        return $this->files->stream($key);
    }
}
