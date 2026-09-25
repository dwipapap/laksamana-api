<?php

namespace App\Modules\Finance\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Finance\Services\Brankas;
use App\Modules\Finance\Services\FinanceConflict;
use App\Modules\Finance\Services\KasKecilRecords;
use App\Modules\Finance\Services\VaultMiss;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/finance/vault — Panel Brankas (see docs/api/finance.md), gated by
 * the `brankas` Modul; plus Kas Kecil's payment plan (`bayar`, which lives in
 * the same blob) under /petty-cash/payment-plan for `finance` holders.
 *
 * The legacy blob is split into resources: rekening, piutang, bayar,
 * investor, mutasi (lists of records with `id`) and `setting` (one document).
 * ONE version covers the blob: its `updated_at` (ms). Every write sends it
 * (If-Match or ?version=) and gets the new one back; stale => 409 with the
 * current state. Writes record the session user as updated_by.
 */
class VaultController
{
    public function __construct(
        private readonly Brankas $brankas,
        private readonly OfficeAccess $access,
    ) {}

    public function state(): JsonResponse
    {
        $b = $this->brankas->read();

        return ApiResponse::ok($b, ['version' => $b['updated_at']]);
    }

    public function list(string $list): JsonResponse
    {
        $b = $this->brankas->read();

        return ApiResponse::ok(array_values($b['data'][$list] ?? []), ['version' => $b['updated_at']]);
    }

    public function show(string $list, string $id): JsonResponse
    {
        $b = $this->brankas->read();
        $row = self::find($b['data'][$list] ?? [], $id);

        return $row === null ? self::notFound() : ApiResponse::ok($row, ['version' => $b['updated_at']]);
    }

    /** Body = the record; `id` generated when absent. */
    public function store(Request $r, string $list): JsonResponse
    {
        $rec = $r->json()->all();
        if ($rec === [] || array_is_list($rec)) {
            return ApiResponse::error('validation_failed', 'The body must be a JSON object.', 422);
        }
        $rec['id'] = isset($rec['id']) && is_scalar($rec['id']) && $rec['id'] !== '' ? (string) $rec['id'] : substr($list, 0, 2).bin2hex(random_bytes(5));
        $id = $rec['id'];

        return $this->write($r, function (array $d) use ($list, $rec, $id) {
            if (self::find($d[$list] ?? [], $id) !== null) {
                throw new VaultMiss('exists');
            }
            $d[$list] = [...array_values($d[$list] ?? []), $rec];

            return $d;
        }, fn ($d) => self::find($d[$list], $id), 201);
    }

    public function update(Request $r, string $list, string $id): JsonResponse
    {
        return $this->replaceRecord($r, $list, $id, false);
    }

    public function patch(Request $r, string $list, string $id): JsonResponse
    {
        return $this->replaceRecord($r, $list, $id, true);
    }

    private function replaceRecord(Request $r, string $list, string $id, bool $merge): JsonResponse
    {
        $body = $r->json()->all();
        if (isset($body['id']) && (string) $body['id'] !== $id) {
            return ApiResponse::error('validation_failed', 'The id in the body does not match the URL.', 422);
        }

        return $this->write($r, function (array $d) use ($list, $id, $body, $merge) {
            $rows = array_values($d[$list] ?? []);
            foreach ($rows as $i => $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
                    $rows[$i] = ['id' => $id] + ($merge ? array_replace($row, $body) : $body);
                    $d[$list] = $rows;

                    return $d;
                }
            }
            throw new VaultMiss('not_found');
        }, fn ($d) => self::find($d[$list], $id));
    }

    public function destroy(Request $r, string $list, string $id): JsonResponse
    {
        return $this->write($r, function (array $d) use ($list, $id) {
            $rows = array_values($d[$list] ?? []);
            $keep = array_values(array_filter($rows, fn ($row) => ! (is_array($row) && (string) ($row['id'] ?? '') === $id)));
            if (count($keep) === count($rows)) {
                throw new VaultMiss('not_found');
            }
            $d[$list] = $keep;

            return $d;
        }, fn () => ['deleted' => true]);
    }

    public function setting(): JsonResponse
    {
        $b = $this->brankas->read();

        return ApiResponse::ok((object) ($b['data']['setting'] ?? []), ['version' => $b['updated_at']]);
    }

    /** Body {value: {...}} replaces the settings map (bank mapping of payment methods, …). */
    public function putSetting(Request $r): JsonResponse
    {
        $v = $r->json('value');
        if (! is_array($v)) {
            return ApiResponse::error('validation_failed', 'Send {"value": {...}}.', 422);
        }

        return $this->write($r, function (array $d) use ($v) {
            $d['setting'] = $v === [] ? new \stdClass : $v;

            return $d;
        }, fn ($d) => (object) $d['setting']);
    }

    // ─────────────────────────── Kas Kecil payment plan (module finance) ──

    public function paymentPlan(): JsonResponse
    {
        return $this->list('bayar');
    }

    /** Body {bayar: [...]}: replaces the whole plan (legacy bayarSave), leaves the rest of the vault untouched. */
    public function putPaymentPlan(Request $r): JsonResponse
    {
        $rows = $r->json('bayar');
        if (! is_array($rows)) {
            return ApiResponse::error('validation_failed', 'Send {"bayar": [...]}.', 422);
        }

        return $this->write($r, function (array $d) use ($rows) {
            $d['bayar'] = array_values($rows);

            return $d;
        }, fn ($d) => $d['bayar']);
    }

    // ─────────────────────────── access ──

    public function access(): JsonResponse
    {
        $matrix = $this->brankas->akses();

        return ApiResponse::ok(['matrix' => $matrix, 'roles' => $this->brankas->peran()], ['version' => KasKecilRecords::version($matrix)]);
    }

    /** Module admin only. Body {matrix} — the complete matrix; guarded by its hash. */
    public function putMatrix(Request $r): JsonResponse
    {
        $base = self::baseVersion($r);
        if ($base === null) {
            return self::versionRequired();
        }
        if (! array_key_exists('matrix', $r->json()->all())) {
            return ApiResponse::error('validation_failed', 'Send {"matrix": {...}}.', 422);
        }

        return $this->brankas->db()->transaction(function () use ($r, $base) {
            $this->brankas->db()->select('SELECT `id` FROM `bk_akses` FOR UPDATE');
            $cur = $this->brankas->akses();
            if (! hash_equals(KasKecilRecords::version($cur), $base)) {
                return ApiResponse::error('version_conflict', 'Changed by someone else. Reload and apply your change again.', 409, ['current' => $cur]);
            }
            $m = $this->brankas->saveAkses($r->json('matrix'))['akses'];

            return ApiResponse::ok($m, ['version' => KasKecilRecords::version($m)]);
        });
    }

    /** Module admin only. Body {role}; ''/null = back to the default. Version = hash of the current role (of null when none). */
    public function putRole(Request $r, string $userId): JsonResponse
    {
        $base = self::baseVersion($r);
        if ($base === null) {
            return self::versionRequired();
        }
        if (! array_key_exists('role', $r->json()->all())) {
            return ApiResponse::error('validation_failed', 'Send {"role": "…"}.', 422);
        }

        return $this->brankas->db()->transaction(function () use ($r, $userId, $base) {
            $cur = $this->brankas->db()->selectOne('SELECT `peran` FROM `bk_peran` WHERE `kunci`=? FOR UPDATE', ['#'.$userId]);
            $cur = $cur ? (string) $cur->peran : null;
            if (! hash_equals(KasKecilRecords::version($cur), $base)) {
                return ApiResponse::error('version_conflict', 'Changed by someone else. Reload and apply your change again.', 409, ['current' => $cur]);
            }
            $role = ((array) $this->brankas->saveRole(['kunci' => '#'.$userId, 'peran' => (string) $r->json('role')])['peran'])['#'.$userId] ?? null;

            return ApiResponse::ok($role, ['version' => KasKecilRecords::version($role)]);
        });
    }

    // ─────────────────────────── helpers ──

    /** Version-guarded blob write; $present maps the saved state to the response data. */
    private function write(Request $r, \Closure $fn, \Closure $present, int $status = 200): JsonResponse
    {
        $base = self::baseVersion($r);
        if ($base === null || ! ctype_digit($base)) {
            return self::versionRequired();
        }
        try {
            $out = $this->brankas->mutate((int) $base, $fn, $this->actor($r));
        } catch (FinanceConflict $e) {
            return ApiResponse::error('version_conflict', 'The vault was saved by someone else. Reload and apply your change again.', 409, ['current' => $e->current]);
        } catch (VaultMiss $e) {
            return $e->getMessage() === 'exists'
                ? ApiResponse::error('already_exists', 'A record with this id already exists.', 409)
                : self::notFound();
        }

        return ApiResponse::ok($present($out['data']), ['version' => $out['version']], $status, ['ETag' => '"'.$out['version'].'"']);
    }

    private static function find(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        return null;
    }

    private function actor(Request $r): string
    {
        return (string) ($this->access->userById((string) $r->user()->getKey())['name'] ?? $r->user()->getKey());
    }

    private static function baseVersion(Request $r): ?string
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
