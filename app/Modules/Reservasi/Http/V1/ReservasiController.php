<?php

namespace App\Modules\Reservasi\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Reservasi\Services\DanaMasukGate;
use App\Modules\Reservasi\Services\HapusReservasiGate;
use App\Modules\Reservasi\Services\ReservasiBrowse;
use App\Modules\Reservasi\Services\ReservasiConflict;
use App\Modules\Reservasi\Services\ReservasiGuests;
use App\Modules\Reservasi\Services\ReservasiRecords;
use App\Modules\Reservasi\Services\ReservasiState;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        private readonly ReservasiGuests $guests,
        private readonly ReservasiBrowse $browse,
        private readonly OfficeAccess $access,
    ) {}

    /**
     * Reservations in range, in `created_at` order. `q` (name/phone/table)
     * and `status` (normalised; Cancelled rows drop out unless asked for)
     * narrow it; `page`/`perPage` cut it in Recap display order (date+time,
     * stable) with `meta.total` counting every match. Without the new params
     * the answer is exactly what it always was: every row.
     */
    public function index(Request $r): JsonResponse
    {
        $f = $r->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', 'string', 'max:32'],
        ]);
        if (($f['page'] ?? null) === null && ($f['perPage'] ?? null) === null
            && ($f['q'] ?? null) === null && ($f['status'] ?? null) === null) {
            $rows = $this->records->list($f['from'] ?? null, $f['to'] ?? null);

            return ApiResponse::ok($rows, ['total' => count($rows), 'ver' => $this->state->ver()]);
        }
        $found = $this->browse->filterReservations($f['from'] ?? null, $f['to'] ?? null, $f['q'] ?? null, $f['status'] ?? null);
        $meta = ['total' => $found['total'], 'ver' => $this->state->ver()];
        if (($f['status'] ?? null) !== null) {
            $meta['batal'] = $found['batal'];
        }
        if (($f['page'] ?? null) !== null || ($f['perPage'] ?? null) !== null) {
            $cut = $this->browse->page($this->browse->sortDisplay($found['rows']), $f['page'] ?? null, $f['perPage'] ?? null);
            $meta['total'] = $cut['total'];
            $meta['page'] = $cut['page'];
            $meta['perPage'] = $cut['perPage'];

            return ApiResponse::ok($cut['rows'], $meta);
        }

        return ApiResponse::ok($found['rows'], $meta);
    }

    /**
     * The Recap CSV (exportCSV()): same columns in the same order, BOM'd for
     * Excel. Same filters as the list; paging is ignored — the export always
     * covers every matching row, in display order. READ-ONLY.
     */
    public function export(Request $r): Response
    {
        $f = $r->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', 'string', 'max:32'],
        ]);
        $found = $this->browse->filterReservations($f['from'] ?? null, $f['to'] ?? null, $f['q'] ?? null, $f['status'] ?? null);
        $csv = $this->browse->exportCsv(
            $this->browse->sortDisplay($found['rows']),
            $this->browse->tableFloors($this->records->master()['value'] ?? []));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="reservasi_laksamana_'.ReservasiBrowse::today().'.csv"',
        ]);
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
        if ($merge && $this->danaMasukOnly($r) && ($bad = DanaMasukGate::rejectedFields($body, $cur['row'])) !== []) {
            return ApiResponse::error('forbidden',
                'Through Cashier / Finance only the DP and transfer fields of a reservation can be changed.',
                403, ['fields' => $bad]);
        }
        $row = ['id' => $id] + ($merge ? array_replace($cur['row'], $body) : $body);

        return $this->write(fn () => $this->records->put($row, (int) $v, true, $this->reservationAudit($r, $id)));
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        if (! $this->mayDelete($r)) {
            return ApiResponse::error('forbidden', 'Deleting a reservation needs the Hapus Reservasi permission (master.perms.inputDelete).', 403);
        }
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
        if ($this->danaMasukOnly($r) && ! in_array($section, DanaMasukGate::SECTIONS, true)) {
            return ApiResponse::error('module_not_granted', 'Your account has no access to module [reservasi|service_excellent].', 403);
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
        if ($bad = self::badItemSection($section) ?? self::badItemId($id)) {
            return $bad;
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }
        $value = $r->json('value');
        if (! is_array($value) || array_is_list($value) || ! isset($value['id']) || (string) $value['id'] !== $id) {
            return ApiResponse::error('validation_failed', 'Send {"value": {…}} with its id matching the URL.', 422);
        }

        return $this->write(fn () => $this->records->putItem($section, $id, $value, $v));
    }

    public function deleteItem(Request $r, string $section, string $id): JsonResponse
    {
        if ($bad = self::badItemSection($section) ?? self::badItemId($id)) {
            return $bad;
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }

        return $this->write(fn () => $this->records->deleteItem($section, $id, $v));
    }

    /**
     * The newest 500 audit entries, newest first. `q` narrows them the way
     * the Audit Log search box does (user, role, action, detail);
     * `page`/`perPage` cut them with `meta.total` counting every match.
     * Without the new params the answer is exactly what it always was.
     * Audit has no status dimension: auditCocok() searches text only.
     */
    public function audit(Request $r): JsonResponse
    {
        $f = $r->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        if (($f['page'] ?? null) === null && ($f['perPage'] ?? null) === null && ($f['q'] ?? null) === null) {
            return ApiResponse::ok($this->state->readAudit());
        }
        $list = $this->browse->filterAudit($f['q'] ?? null);
        $meta = ['total' => count($list)];
        if (($f['page'] ?? null) !== null || ($f['perPage'] ?? null) !== null) {
            $cut = $this->browse->page($list, $f['page'] ?? null, $f['perPage'] ?? null);
            $meta['total'] = $cut['total'];
            $meta['page'] = $cut['page'];
            $meta['perPage'] = $cut['perPage'];

            return ApiResponse::ok($cut['rows'], $meta);
        }

        return ApiResponse::ok($list, $meta);
    }

    /**
     * Guest summary over the whole history (G-07): the legacy `ringkasTamu`
     * shape ({sebelum, tamu}) inside the v1 envelope, so the windowed Vue
     * client can merge it with its own rows exactly like the old screen did
     * (profilGabung). `before` bounds the history (twin of legacy `sebelum`);
     * without it every dated row counts. `phone` narrows the map to that one
     * number (normalised the legacy way); an unparseable phone matches nothing.
     */
    public function guestSummary(Request $r): JsonResponse
    {
        $f = $r->validate([
            'before' => ['nullable', 'date_format:Y-m-d'],
            'phone' => ['nullable', 'string', 'max:64'],
        ]);
        $out = $this->guests->summary($f['before'] ?? null, $f['phone'] ?? null);

        return ApiResponse::ok(
            ['sebelum' => $out['sebelum'], 'tamu' => (object) $out['tamu']],
            ['ver' => $this->state->ver()]
        );
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
        if (isset($out['deleted'], $out['version'])) {
            return ApiResponse::ok(['deleted' => true], ['version' => $out['version']], $status, ['ETag' => '"'.$out['version'].'"']);
        }

        return isset($out['row']) ? self::withVersion($out, $status) : ApiResponse::ok($out, [], $status);
    }

    /** The acting User always comes from the token, never from the body. */
    /**
     * True when the caller came in through the Dana Masuk door only: it holds
     * cashier/finance but neither reservasi nor service_excellent (G-14).
     */
    /** Module admins of reservasi count as `admin`, as the old SSO did (deploy/reservasi:3683). */
    private function mayDelete(Request $r): bool
    {
        $id = (string) $r->user()->getKey();
        $role = $this->access->isModuleAdmin($id, 'reservasi') ? 'admin' : $this->state->role($id);

        return HapusReservasiGate::allowed($role, ($this->state->readMaster() ?? [])['perms'] ?? null);
    }

    private function danaMasukOnly(Request $r): bool
    {
        $id = (string) $r->user()->getKey();

        return ! $this->access->hasModule($id, 'reservasi') && ! $this->access->hasModule($id, 'service_excellent');
    }

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

    /** The id also becomes a photo filename, so keep it short and printable. */
    private static function badItemId(string $id): ?JsonResponse
    {
        return strlen($id) <= 64 && ! preg_match('/[\x00-\x1F\x7F]/', $id)
            ? null
            : ApiResponse::error('validation_failed', 'The item id must be at most 64 printable characters.', 422);
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
