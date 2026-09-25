<?php

namespace App\Modules\Akademi\Http\V1;

use App\Modules\Akademi\Services\AkademiFiles;
use App\Modules\Akademi\Services\AkademiRecords;
use App\Modules\Akademi\Services\AkademiState;
use App\Modules\Akademi\Services\AkademiStats;
use App\Modules\Akademi\Services\RecordConflict;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/akademi — the full akademi contract (see docs/api/akademi.md).
 * Everything the laksamana-office akademi screens do is reachable here:
 * crew dashboard & certificates, my materials, monthly programs, library,
 * org structure, team progress (managers), material/program/crew management
 * (admins), activity log, and receipt files.
 *
 * Concurrency: every record/cell/document has a `version` (ETag). Writes must
 * send it (If-Match header or `version` in the body); a stale one => 409 with
 * the current record in error.details.current.
 */
class AkademiController
{
    public function __construct(
        private readonly AkademiRecords $records,
        private readonly AkademiState $state,
        private readonly AkademiStats $stats,
        private readonly AkademiFiles $files,
    ) {}

    private function actor(Request $r): string
    {
        return (string) $r->user()->getKey();
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

    private static function withVersion(array $row, int $version): JsonResponse
    {
        return ApiResponse::ok($row, ['version' => $version], 200, ['ETag' => '"'.$version.'"']);
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
        if (! isset(AkademiRecords::RESOURCES[$resource])) {
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
        return $this->safe(fn () => $this->stats->stats());
    }

    // ─────────────────────────── training stats (HR read model) ──

    /**
     * Per-user {mandPct,mandTotal,mandDone,total,done,certified} — the same
     * numbers the akademi client computes, served for the HR Panel.
     * Percentages only, no material or quiz content.
     */
    public function trainingStats(): JsonResponse
    {
        return ApiResponse::ok($this->stats->trainingStats());
    }

    public function trainingStatsFor(string $user): JsonResponse
    {
        $all = $this->stats->trainingStats();
        $all = is_object($all) ? [] : $all;

        return isset($all[$user])
            ? ApiResponse::ok($all[$user])
            : ApiResponse::error('not_found', 'No training stats for this user.', 404);
    }

    // ─────────────────────────── generic records ──

    public function index(Request $r, string $resource): JsonResponse
    {
        $this->res($resource);
        $f = $r->validate([
            'role' => ['nullable', 'string'], 'division' => ['nullable', 'string'], 'active' => ['nullable', 'string'],
            'type' => ['nullable', 'string'], 'cat' => ['nullable', 'string'],
            'mandatory' => ['nullable', 'string'], 'published' => ['nullable', 'string'],
            'bulan' => ['nullable', 'string', 'max:7'],
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
        $found = $this->records->find($resource, $id);

        return $found ? self::withVersion($found['row'], $found['version']) : ApiResponse::error('not_found', 'Record not found.', 404);
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
            $res = $this->records->create($resource, $data, $this->actor($r));

            return ApiResponse::created($res['row'], ['version' => $res['version']]);
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

            return self::withVersion($res['row'], $res['version']);
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

    // ─────────────────────────── progress (one cell per crew+material) ──

    public function progressIndex(Request $r): JsonResponse
    {
        $d = $r->validate([
            'user' => ['nullable', 'string', 'max:64'], 'material' => ['nullable', 'string', 'max:64'],
        ]);
        $out = $this->records->progressList($d['user'] ?? null, $d['material'] ?? null);

        return ApiResponse::ok($out['rows'], ['total' => $out['total']]);
    }

    public function progressShow(string $user, string $material): JsonResponse
    {
        $cell = $this->records->progressFind($user, $material);

        return $cell ? self::withVersion($cell['entry'], $cell['version']) : ApiResponse::error('not_found', 'No progress for this crew and material.', 404);
    }

    /**
     * Replace one progress cell. Body = the entry object ({status:'done'} or
     * quiz {attempts,score,passed,lastAt,...}). New cells need no version;
     * existing ones need If-Match / "version".
     */
    public function progressPut(Request $r, string $user, string $material): JsonResponse
    {
        $entry = $r->json()->all();
        if (! is_array($entry) || array_is_list($entry)) {
            return ApiResponse::error('invalid_request', 'Body must be a JSON object (the progress entry).', 422);
        }
        $base = $this->baseVersion($r);
        unset($entry['version'], $entry['updatedAt']);
        $exists = $this->records->progressFind($user, $material);
        if ($exists && ($base === null || ! ctype_digit($base))) {
            return ApiResponse::error('version_required', 'Send the version you last read (If-Match header or "version").', 428);
        }
        try {
            $cell = $this->records->progressPut($user, $material, $entry,
                $base === null ? null : (int) $base, $this->actor($r));

            return self::withVersion($cell['entry'], $cell['version']);
        } catch (RecordConflict $e) {
            return self::conflict($e);
        }
    }

    public function progressDelete(Request $r, string $user, string $material): JsonResponse
    {
        $base = $this->baseVersion($r);
        if ($base === null || ! ctype_digit($base)) {
            return ApiResponse::error('version_required', 'Send the version you last read (If-Match header or ?version=).', 428);
        }
        try {
            $this->records->progressDelete($user, $material, (int) $base);

            return ApiResponse::ok(['deleted' => true, 'userId' => $user, 'materialId' => $material]);
        } catch (RecordConflict $e) {
            return self::conflict($e);
        } catch (RuntimeException $e) {
            return $e->getMessage() === 'not_found'
                ? ApiResponse::error('not_found', 'No progress for this crew and material.', 404)
                : throw $e;
        }
    }

    // ─────────────────────────── program progress ──

    public function progProgIndex(Request $r): JsonResponse
    {
        $d = $r->validate([
            'user' => ['nullable', 'string', 'max:64'], 'program' => ['nullable', 'string', 'max:64'],
            'material' => ['nullable', 'string', 'max:64'],
        ]);
        $out = $this->records->progProgList($d['user'] ?? null, $d['program'] ?? null, $d['material'] ?? null);

        return ApiResponse::ok($out['rows'], ['total' => $out['total']]);
    }

    /** Mark one program item done: body {done?, score?, at?}. Version rule like progress. */
    public function progProgPut(Request $r, string $user, string $program, string $material): JsonResponse
    {
        $entry = $r->json()->all();
        if (! is_array($entry) || array_is_list($entry)) {
            return ApiResponse::error('invalid_request', 'Body must be a JSON object (the program entry).', 422);
        }
        $base = $this->baseVersion($r);
        unset($entry['version'], $entry['updatedAt']);
        if (($base === null || ! ctype_digit($base)) && $this->records->progProgFind($user, $program, $material)) {
            return ApiResponse::error('version_required', 'Send the version you last read (If-Match header or "version").', 428);
        }
        try {
            $cell = $this->records->progProgPut($user, $program, $material, $entry,
                $base === null || ! ctype_digit($base) ? null : (int) $base);

            return self::withVersion($cell['entry'], $cell['version'] ?? 0);
        } catch (RecordConflict $e) {
            return self::conflict($e);
        }
    }

    public function progProgDelete(Request $r, string $user, string $program, string $material): JsonResponse
    {
        $base = $this->baseVersion($r);
        if ($base === null || ! ctype_digit($base)) {
            return ApiResponse::error('version_required', 'Send the version you last read (If-Match header or ?version=).', 428);
        }
        try {
            $this->records->progProgDelete($user, $program, $material, (int) $base);

            return ApiResponse::ok(['deleted' => true, 'userId' => $user, 'programId' => $program, 'materialId' => $material]);
        } catch (RecordConflict $e) {
            return self::conflict($e);
        } catch (RuntimeException $e) {
            return $e->getMessage() === 'not_found'
                ? ApiResponse::error('not_found', 'No program entry for this crew, program and material.', 404)
                : throw $e;
        }
    }

    // ─────────────────────────── activity trail ──

    public function activity(Request $r): JsonResponse
    {
        $d = $r->validate([
            'user' => ['nullable', 'string', 'max:64'], 'action' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
        $out = $this->records->activity($d['user'] ?? null, $d['action'] ?? null, (int) ($d['limit'] ?? 200));

        return ApiResponse::ok($out['rows'], ['total' => $out['total']]);
    }

    public function logActivity(Request $r): JsonResponse
    {
        $d = $r->validate(['action' => ['required', 'string', 'max:64'], 'detail' => ['nullable', 'string', 'max:500']]);
        $row = $this->records->logActivity($d, $this->actor($r))['row'];

        return ApiResponse::created($row);
    }

    // ─────────────────────────── settings documents ──

    public function document(string $doc): JsonResponse
    {
        if (! in_array($doc, AkademiRecords::DOCUMENTS, true)) {
            return ApiResponse::error('not_found', 'Unknown document.', 404);
        }
        $d = $this->records->document($doc);

        return ApiResponse::ok($d['value'], ['version' => $d['version']], 200, ['ETag' => '"'.$d['version'].'"']);
    }

    public function putDocument(Request $r, string $doc): JsonResponse
    {
        if (! in_array($doc, AkademiRecords::DOCUMENTS, true)) {
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

            return ApiResponse::created($out + ['url' => url('/api/v1/akademi/files/'.$out['key'])]);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_file', $e->getMessage(), 422);
        }
    }

    public function file(string $key): Response
    {
        return $this->files->stream($key);
    }
}
