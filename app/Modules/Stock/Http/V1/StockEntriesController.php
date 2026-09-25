<?php

namespace App\Modules\Stock\Http\V1;

use App\Modules\Stock\Services\StockCk;
use App\Modules\Stock\Services\StockConflict;
use App\Modules\Stock\Services\StockEntryRecords;
use App\Modules\Stock\Services\StockLog;
use App\Modules\Stock\Services\StockTeamScope;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * /api/v1/stock — the Usage Panel (usage, waste, handovers, opname), the Central
 * Kitchen ledger and the activity log (see docs/api/stock.md).
 *
 * `kind` (usage | waste | handovers | opname | ck) comes from the route defaults.
 * Lists and single reads of usage / waste / handovers are team-scoped like the
 * old endpoints (StockTeamScope, answered from the Bearer user); writes go
 * through the same scoped lookup. `pic` (and a log's `aktor`) is always the
 * acting user's name.
 */
class StockEntriesController
{
    public function __construct(
        private readonly StockEntryRecords $records,
        private readonly StockCk $ck,
        private readonly StockLog $log,
        private readonly StockTeamScope $scope,
    ) {}

    private function teams(): ?array
    {
        return $this->scope->forRequest(null);
    }

    public function index(Request $r, string $kind): JsonResponse
    {
        $rows = $this->records->list($kind, $this->teams(), self::q($r, 'from'), self::q($r, 'to'));

        return ApiResponse::ok(array_column($rows, 'record'), [
            'total' => count($rows),
            'versions' => (object) array_combine(array_map(fn ($x) => $x['record']['id'], $rows), array_column($rows, 'version')),
        ]);
    }

    public function show(string $id, string $kind): JsonResponse
    {
        $row = $this->records->find($kind, $id, $this->teams());

        return $row ? self::withVersion($row) : self::notFound();
    }

    public function photo(string $id, string $kind): JsonResponse
    {
        $p = $this->records->photo($kind, $id, $this->teams());

        return $p ? ApiResponse::ok($p) : self::notFound();
    }

    public function store(Request $r, string $kind): JsonResponse
    {
        if (($b = self::body($r)) === null) {
            return self::badBody();
        }
        $b->pic = (string) $r->user()->name;

        return $this->write(fn () => $this->records->create($kind, $b, $this->teams()), 201);
    }

    public function update(Request $r, string $id, string $kind): JsonResponse
    {
        if (($b = self::body($r)) === null) {
            return self::badBody();
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }
        $b->pic = (string) $r->user()->name;

        return $this->write(fn () => $this->records->patch($kind, $id, $b, $v, $this->teams()));
    }

    public function destroy(Request $r, string $id, string $kind): JsonResponse
    {
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }

        return $this->write(function () use ($kind, $id, $v) {
            $this->records->delete($kind, $id, $v, $this->teams());

            return null;
        });
    }

    // ─────────────────────────── central kitchen ──

    public function ckBalance(): JsonResponse
    {
        return ApiResponse::ok($this->ck->balance());
    }

    /** Outlet → CK delivery (the Ordering "Kirim ke CK"): the balance rises at once. */
    public function ckDeliver(Request $r): JsonResponse
    {
        if (($b = self::body($r)) === null) {
            return self::badBody();
        }
        $b->pic = (string) $r->user()->name;
        $res = $this->ck->send($b);
        if ($res['status'] !== 'success') {
            return ApiResponse::error('validation_failed', $res['message'], 422);
        }

        return self::withVersion($this->records->find('ck', $res['id'], null), 201);
    }

    // ─────────────────────────── activity log ──

    public function logIndex(Request $r): JsonResponse
    {
        $limit = $r->query('limit', 300);

        return ApiResponse::ok($this->log->read(self::q($r, 'from'), self::q($r, 'to'), self::q($r, 'modul'), self::q($r, 'q'),
            is_numeric($limit) ? (int) $limit : 300));
    }

    /** Body {entries:[{modul, aksi, tim, ringkas, data}]}; `aktor` = the acting user. Never fails the caller. */
    public function logStore(Request $r): JsonResponse
    {
        $b = json_decode((string) $r->getContent());
        if (! $b instanceof stdClass || ! isset($b->entries) || ! is_array($b->entries)) {
            return ApiResponse::error('validation_failed', 'Send {"entries": [...]}.', 422);
        }
        $name = (string) $r->user()->name;
        foreach ($b->entries as $e) {
            if ($e instanceof stdClass) {
                $e->aktor = $name;
            }
        }

        return ApiResponse::ok(['recorded' => $this->log->write($b->entries)['dicatat']], [], 201);
    }

    // ─────────────────────────── helpers ──

    private function write(\Closure $fn, int $status = 200): JsonResponse
    {
        try {
            $row = $fn();
        } catch (StockConflict $e) {
            return match ($e->getMessage()) {
                'not_found' => self::notFound(),
                'invalid' => ApiResponse::error('validation_failed', (string) $e->current, 422),
                default => ApiResponse::error('version_conflict', 'The record was changed elsewhere. Reload it and apply your change again.', 409,
                    ['current' => $e->current]),
            };
        }

        return $row === null ? ApiResponse::ok(['deleted' => true]) : self::withVersion($row, $status);
    }

    private static function q(Request $r, string $k): string
    {
        $v = $r->query($k, '');

        return is_string($v) ? trim($v) : '';
    }

    private static function body(Request $r): ?stdClass
    {
        $b = json_decode((string) $r->getContent());

        return $b instanceof stdClass ? $b : null;
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

    private static function withVersion(array $row, int $status = 200): JsonResponse
    {
        return ApiResponse::ok($row['record'], ['version' => $row['version']], $status, ['ETag' => '"'.$row['version'].'"']);
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
