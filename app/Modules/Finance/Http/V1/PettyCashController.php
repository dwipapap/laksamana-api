<?php

namespace App\Modules\Finance\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Finance\Services\FinanceConflict;
use App\Modules\Finance\Services\KasKecil;
use App\Modules\Finance\Services\KasKecilRecords;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/finance/petty-cash — Kas Kecil (see docs/api/finance.md): Input
 * Transaksi, Buku Kas, Pos & Kategori, Akses Halaman.
 *
 * Concurrency: every transaction / source / category / the access matrix /
 * a role carries a content-hash `version`; writes send it as If-Match (or
 * ?version=); stale => 409 with the current value. Validation errors keep the
 * legacy Indonesian messages (422 invalid_request).
 */
class PettyCashController
{
    private const LISTS = ['sources' => 'kk_pos', 'categories' => 'kk_kategori'];

    public function __construct(
        private readonly KasKecil $kas,
        private readonly KasKecilRecords $records,
        private readonly OfficeAccess $access,
    ) {}

    /** Bootstrap: sources, categories, transactions (with split rows), access matrix and roles. */
    public function state(): JsonResponse
    {
        return ApiResponse::ok($this->kas->read());
    }

    // ─────────────────────────── transactions ──

    public function transactions(Request $r): JsonResponse
    {
        $f = $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $rows = $this->records->transactions($f['from'] ?? null, $f['to'] ?? null);

        return ApiResponse::ok($rows, ['total' => count($rows), 'versions' => (object) array_combine(
            array_map(fn ($t) => (string) $t['id'], $rows), array_map([KasKecilRecords::class, 'version'], $rows))]);
    }

    public function transaction(int $id): JsonResponse
    {
        $t = $this->records->transaction($id);

        return $t ? self::withVersion($t) : self::notFound();
    }

    /** Body: {tgl, keterangan, kategori_id?, input?, bon?, baris:[{pos_id, debet, kredit}]}. */
    public function createTransaction(Request $r): JsonResponse
    {
        return $this->safe(fn () => self::withVersion($this->records->createTransaction($r->json()->all(), $this->actor($r)), 201));
    }

    /** PUT replaces the transaction and its split rows. */
    public function updateTransaction(Request $r, int $id): JsonResponse
    {
        return $this->versioned($r, fn ($v) => $this->records->updateTransaction($id, $r->json()->all(), $v, false));
    }

    /** PATCH changes only the administrative markers: {input?: bool, bon?: bool}. */
    public function markTransaction(Request $r, int $id): JsonResponse
    {
        $body = $r->json()->all();
        if (! array_key_exists('input', $body) && ! array_key_exists('bon', $body)) {
            return ApiResponse::error('validation_failed', 'Send {"input": bool} and/or {"bon": bool}.', 422);
        }

        return $this->versioned($r, fn ($v) => $this->records->updateTransaction($id, $body, $v, true));
    }

    public function deleteTransaction(Request $r, int $id): JsonResponse
    {
        return $this->versioned($r, fn ($v) => $this->records->deleteTransaction($id, $v) ? ['deleted' => true] : null, false);
    }

    // ─────────────────────────── sources & categories ──

    public function items(string $list): JsonResponse
    {
        $rows = $this->kas->read()[$list === 'sources' ? 'pos' : 'kategori'];

        return ApiResponse::ok($rows, ['versions' => (object) array_combine(
            array_map(fn ($x) => (string) $x['id'], $rows), array_map([KasKecilRecords::class, 'version'], $rows))]);
    }

    /** Body: {nama, urut?}. */
    public function createItem(Request $r, string $list): JsonResponse
    {
        $body = $r->json()->all();
        unset($body['id']);

        return $this->safe(fn () => self::withVersion($this->records->item(self::LISTS[$list], $this->kas->saveListItem(self::LISTS[$list], $body)['id']), 201));
    }

    /** Body: any of {nama, urut, aktif}. */
    public function updateItem(Request $r, string $list, int $id): JsonResponse
    {
        return $this->versioned($r, fn ($v) => $this->records->updateItem(self::LISTS[$list], $id, $r->json()->all(), $v));
    }

    /** Refused (422) once the item is used by a transaction — deactivate it instead. */
    public function deleteItem(Request $r, string $list, int $id): JsonResponse
    {
        return $this->versioned($r, fn ($v) => $this->records->deleteItem(self::LISTS[$list], $id, $v) ? ['deleted' => true] : null, false);
    }

    // ─────────────────────────── Akses Halaman ──

    public function access(): JsonResponse
    {
        $matrix = $this->kas->akses();
        $roles = $this->kas->peran();

        return ApiResponse::ok(['matrix' => $matrix, 'roles' => $roles], [
            'version' => KasKecilRecords::version($matrix),
            'roleVersions' => (object) array_map(fn ($p) => KasKecilRecords::version($p), (array) $roles),
        ]);
    }

    /** Body: {matrix: {role: {page: 0|1|2}}} — the COMPLETE matrix of differences from the default. */
    public function putMatrix(Request $r): JsonResponse
    {
        $body = $r->json()->all();
        if (! array_key_exists('matrix', $body)) {
            return ApiResponse::error('validation_failed', 'Send {"matrix": {...}}.', 422);
        }

        return $this->versioned($r, fn ($v) => $this->records->saveMatrix($body['matrix'], $v), true, true);
    }

    /** Body: {role: "staf"|"manajemen"|"viewer"|""|null}; empty = back to the default role. */
    public function putRole(Request $r, string $userId): JsonResponse
    {
        $body = $r->json()->all();
        if (! array_key_exists('role', $body)) {
            return ApiResponse::error('validation_failed', 'Send {"role": "…"}.', 422);
        }
        $role = $body['role'] === null ? null : KasKecil::s($body['role']);

        return $this->versioned($r, function ($v) use ($userId, $role) {
            $roles = (array) $this->records->saveRole($userId, $role, $v);

            return $roles['#'.$userId] ?? null;
        }, true, true);
    }

    // ─────────────────────────── helpers ──

    private function actor(Request $r): string
    {
        return (string) ($this->access->userById((string) $r->user()->getKey())['name'] ?? $r->user()->getKey());
    }

    /**
     * Version-guarded write. $fn gets the base version and returns the new value
     * (null = not found, unless $nullIsValue).
     */
    private function versioned(Request $r, \Closure $fn, bool $withVersion = true, bool $nullIsValue = false): JsonResponse
    {
        $h = $r->header('If-Match');
        $v = is_string($h) && $h !== '' ? trim($h, ' "W/') : $r->query('version');
        if (! is_string($v) || $v === '') {
            return ApiResponse::error('version_required', 'Send the version you edited as If-Match (or ?version=).', 428);
        }

        return $this->safe(function () use ($fn, $v, $withVersion, $nullIsValue) {
            try {
                $out = $fn($v);
            } catch (FinanceConflict $e) {
                return ApiResponse::error('version_conflict', 'Changed by someone else. Reload and apply your change again.', 409, ['current' => $e->current]);
            }
            if ($out === null && ! $nullIsValue) {
                return self::notFound();
            }

            return $withVersion ? self::withVersion($out) : ApiResponse::ok($out);
        });
    }

    /** Legacy validation messages (RuntimeException) become 422 invalid_request. */
    private function safe(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (FinanceConflict $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
    }

    private static function withVersion(mixed $data, int $status = 200): JsonResponse
    {
        $v = KasKecilRecords::version($data);

        return ApiResponse::ok($data, ['version' => $v], $status, ['ETag' => '"'.$v.'"']);
    }

    private static function notFound(): JsonResponse
    {
        return ApiResponse::error('not_found', 'Not found.', 404);
    }
}
