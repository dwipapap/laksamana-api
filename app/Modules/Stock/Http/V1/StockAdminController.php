<?php

namespace App\Modules\Stock\Http\V1;

use App\Modules\Stock\Services\StockConflict;
use App\Modules\Stock\Services\StockSettings;
use App\Modules\Stock\Services\StockTraining;
use App\Modules\Stock\Services\StockTrainingError;
use App\Modules\Stock\Services\StockUsers;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/** The admin resources of Ordering/Purchasing: crew, settings and training files. */
class StockAdminController
{
    public function __construct(
        private readonly StockUsers $users,
        private readonly StockSettings $settings,
        private readonly StockTraining $training,
    ) {}

    public function userIndex(string $kind): JsonResponse
    {
        $rows = $this->users->list(StockUsers::table($kind));

        return ApiResponse::ok(array_column($rows, 'record'), [
            'total' => count($rows),
            'versions' => (object) array_combine(array_map(fn ($r) => $r['record']['id'], $rows), array_column($rows, 'version')),
        ]);
    }

    public function userShow(string $id, string $kind): JsonResponse
    {
        $row = $this->users->find(StockUsers::table($kind), $id);

        return $row ? self::withVersion($row['record'], $row['version']) : self::notFound();
    }

    public function userStore(Request $r, string $kind): JsonResponse
    {
        $body = self::body($r);
        if ($body === null) {
            return self::badBody();
        }

        return $this->userWrite(fn () => $this->users->saveV1(StockUsers::table($kind), null, $body, null), 201);
    }

    public function userUpdate(Request $r, string $id, string $kind): JsonResponse
    {
        $body = self::body($r);
        if ($body === null) {
            return self::badBody();
        }
        if (isset($body['id']) && (string) $body['id'] !== $id) {
            return ApiResponse::error('validation_failed', 'The id in the body does not match the URL.', 422);
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }

        return $this->userWrite(fn () => $this->users->saveV1(StockUsers::table($kind), $id, $body, $v));
    }

    public function userDestroy(Request $r, string $id, string $kind): JsonResponse
    {
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }

        return $this->userWrite(function () use ($kind, $id, $v) {
            $this->users->deleteV1(StockUsers::table($kind), $id, $v);

            return null;
        });
    }

    /** The Ordering roster seed: upsert by id, never delete. */
    public function userImport(Request $r): JsonResponse
    {
        $b = json_decode((string) $r->getContent());
        if (! $b instanceof stdClass || ! isset($b->users) || ! is_array($b->users)) {
            return ApiResponse::error('validation_failed', 'Send {"users": [...]}.', 422);
        }
        $out = $this->users->seedOrdering($b->users);
        if (($out['status'] ?? '') === 'error') {
            return ApiResponse::error('validation_failed', $out['message'], 422);
        }

        return ApiResponse::ok(['seeded' => $out['seeded']]);
    }

    private function userWrite(\Closure $fn, int $status = 200): JsonResponse
    {
        try {
            $row = $fn();
        } catch (StockConflict $e) {
            return self::conflict($e);
        }

        return $row ? self::withVersion($row['record'], $row['version'], $status) : ApiResponse::ok(['deleted' => true]);
    }

    public function settingsShow(string $module): JsonResponse
    {
        $row = $this->settings->readV1($module);

        return ApiResponse::ok($row['record'], ['version' => $row['version'], 'available' => $row['available']],
            200, ['ETag' => '"'.$row['version'].'"']);
    }

    public function settingsPut(Request $r, string $module): JsonResponse
    {
        $body = self::body($r);
        if ($body === null) {
            return self::badBody();
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }
        try {
            $row = $this->settings->writeV1($module, $body, $v);
        } catch (StockConflict $e) {
            return self::conflict($e);
        }

        return ApiResponse::ok($row['record'], ['version' => $row['version'], 'available' => $row['available']],
            200, ['ETag' => '"'.$row['version'].'"']);
    }

    public function trainingSummary(): JsonResponse
    {
        $row = array_diff_key($this->training->summary(), ['status' => 1]);

        return ApiResponse::ok($row);
    }

    public function trainingList(string $target): JsonResponse
    {
        try {
            $files = $this->training->list($target);
        } catch (StockTrainingError $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), $e->status);
        }

        return ApiResponse::ok($files, ['total' => count($files)]);
    }

    public function trainingDownload(string $target, string $name): Response
    {
        try {
            $path = $this->training->path($target, $name);
        } catch (StockTrainingError $e) {
            return ApiResponse::error($e->status === 404 ? 'not_found' : 'invalid_request', $e->getMessage(), $e->status);
        }
        $safe = basename($path);

        return response()->file($path, [
            'Content-Disposition' => 'attachment; filename="'.$safe.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function trainingStore(Request $r): JsonResponse
    {
        $body = self::body($r);
        if ($body === null) {
            return self::badBody();
        }
        try {
            $file = $this->training->saveV1(
                (string) ($body['target'] ?? ''),
                $body['fileName'] ?? $body['filename'] ?? '',
                $body['contentB64'] ?? $body['content_b64'] ?? ''
            );
        } catch (StockTrainingError $e) {
            return ApiResponse::error($e->status === 404 ? 'not_found' : 'invalid_request', $e->getMessage(), $e->status);
        }

        return ApiResponse::ok($file, [], 201);
    }

    private static function body(Request $r): ?array
    {
        $b = json_decode((string) $r->getContent());

        return $b instanceof stdClass ? (array) $b : null;
    }

    private static function version(Request $r): ?string
    {
        $h = $r->header('If-Match');
        if (is_string($h) && $h !== '') {
            return trim($h, ' "W/');
        }
        $q = $r->query('version');

        return is_string($q) && $q !== '' ? $q : null;
    }

    private static function withVersion(array $data, string $version, int $status = 200): JsonResponse
    {
        return ApiResponse::ok($data, ['version' => $version], $status, ['ETag' => '"'.$version.'"']);
    }

    private static function conflict(StockConflict $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'not_found' => self::notFound(),
            'exists' => ApiResponse::error('already_exists', 'A record with this id already exists.', 409),
            'last_admin' => ApiResponse::error('last_admin', 'The last admin cannot be deleted.', 409),
            'settings_unavailable' => ApiResponse::error('settings_unavailable', (string) $e->current, 503),
            'invalid' => ApiResponse::error('validation_failed', (string) $e->current, 422),
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
