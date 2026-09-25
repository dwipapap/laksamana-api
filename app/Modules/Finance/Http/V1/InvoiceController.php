<?php

namespace App\Modules\Finance\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Finance\Services\Invoices;
use App\Modules\Finance\Services\KasKecilRecords;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/finance/invoices — the invoice / kwitansi queue (see docs/api/finance.md).
 *
 * Requests, their status and the printable file are used by Reservasi and
 * Marketing, so those three need ANY of the modules finance/reservasi/marketing;
 * deciding, settings and signatories are Finance's (module `finance`).
 * The requester / decider is always the session user.
 *
 * Versions: a request row, the settings map and a signatory carry a content
 * hash; writes to them need it (If-Match or ?version=), stale => 409.
 */
class InvoiceController
{
    private const CALLERS = ['finance', 'reservasi', 'marketing'];

    public function __construct(
        private readonly Invoices $inv,
        private readonly OfficeAccess $access,
    ) {}

    // ─────────────────────────── callers (Reservasi, Marketing, Finance) ──

    /** Body {resId, jenis?: KWITANSI|INV_DP|INV_LUNAS, ringkas?: {...}} — idempotent per resId. */
    public function request(Request $r): JsonResponse
    {
        if ($denied = $this->callerOnly($r)) {
            return $denied;
        }
        $in = $r->json()->all();
        $in['oleh'] = $this->actor($r);
        try {
            $row = $this->inv->request($in);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }

        return self::withVersion($row);
    }

    /** ?res=a,b,c (at most 400) → {resId: request} without images. */
    public function status(Request $r): JsonResponse
    {
        if ($denied = $this->callerOnly($r)) {
            return $denied;
        }
        $res = (string) $r->query('res', '');

        return ApiResponse::ok((object) $this->inv->statuses($res === '' ? [] : explode(',', $res)));
    }

    /** The printable document of an ISSUED request: number, stamp and signature images. 404 until issued. */
    public function file(Request $r, string $resId): JsonResponse
    {
        if ($denied = $this->callerOnly($r)) {
            return $denied;
        }
        $f = $this->inv->file($resId);

        return $f['ada'] ? ApiResponse::ok($f) : ApiResponse::error('not_issued', 'No issued document for this reservation yet.', 404);
    }

    // ─────────────────────────── Finance: queue & decisions ──

    public function index(Request $r): JsonResponse
    {
        $f = $r->validate(['status' => ['nullable', 'in:MENUNGGU,DIBUAT,DITOLAK'], 'jenis' => ['nullable', 'in:KWITANSI,INV_DP,INV_LUNAS']]);
        $rows = array_values(array_filter($this->inv->list(), fn ($x) => (empty($f['status']) || $x['status'] === $f['status']) && (empty($f['jenis']) || $x['jenis'] === $f['jenis'])));

        return ApiResponse::ok($rows, ['total' => count($rows), 'queue' => $this->inv->queueCount(),
            'versions' => (object) array_combine(array_column($rows, 'id'), array_map([KasKecilRecords::class, 'version'], $rows))]);
    }

    public function queue(): JsonResponse
    {
        return ApiResponse::ok($this->inv->queueCount());
    }

    public function show(string $id): JsonResponse
    {
        $row = $this->find($id);

        return $row ? self::withVersion($row) : self::notFound();
    }

    /** Body {aksi: buat|tolak|batal, catatan?, penanda?: [signatoryId…]}; needs the request's version. */
    public function decide(Request $r, string $id): JsonResponse
    {
        $cur = $this->find($id);
        if (! $cur) {
            return self::notFound();
        }
        if ($bad = $this->stale($r, $cur)) {
            return $bad;
        }
        $in = ['id' => $id, 'oleh' => $this->actor($r)] + array_intersect_key($r->json()->all(), ['aksi' => 1, 'catatan' => 1, 'penanda' => 1]);
        try {
            return self::withVersion($this->inv->decide($in));
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
    }

    // ─────────────────────────── Finance: settings & signatories ──

    public function settings(): JsonResponse
    {
        return self::withVersion($this->inv->settings());
    }

    /** Body: any of prefix, prefixInvoice, cap, penandaDefault, penandaDefaultInvoice (+ legacy ttd/penandaNama/penandaJabatan). */
    public function putSettings(Request $r): JsonResponse
    {
        if ($bad = $this->stale($r, $this->inv->settings())) {
            return $bad;
        }

        return self::withVersion($this->inv->saveSettings($r->json()->all()));
    }

    public function signatories(): JsonResponse
    {
        $rows = $this->inv->signatories(true);

        return ApiResponse::ok($rows, ['versions' => (object) array_combine(array_column($rows, 'id'), array_map([KasKecilRecords::class, 'version'], $rows))]);
    }

    /** Body {nama, jabatan?, ttd? (data URI), urut?, aktif?}. */
    public function createSignatory(Request $r): JsonResponse
    {
        $in = $r->json()->all();
        unset($in['id']);
        $before = array_column($this->inv->signatories(true), 'id');
        try {
            $rows = $this->inv->saveSignatory($in);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
        $new = array_values(array_filter($rows, fn ($x) => ! in_array($x['id'], $before, true)))[0];

        return self::withVersion($new, 201);
    }

    /** Body: the fields to change; `ttd` is replaced only when sent. */
    public function updateSignatory(Request $r, string $id): JsonResponse
    {
        $cur = $this->signatory($id);
        if (! $cur) {
            return self::notFound();
        }
        if ($bad = $this->stale($r, $cur)) {
            return $bad;
        }
        $in = ['id' => $id] + $r->json()->all() + ['nama' => $cur['nama'], 'jabatan' => $cur['jabatan'], 'urut' => $cur['urut'], 'aktif' => $cur['aktif']];
        try {
            $this->inv->saveSignatory($in);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }

        return self::withVersion($this->signatory($id));
    }

    /** Refused (422) once the signatory is on an issued document — deactivate instead. */
    public function deleteSignatory(Request $r, string $id): JsonResponse
    {
        $cur = $this->signatory($id);
        if (! $cur) {
            return self::notFound();
        }
        if ($bad = $this->stale($r, $cur)) {
            return $bad;
        }
        try {
            $this->inv->deleteSignatory($id);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }

        return ApiResponse::ok(['deleted' => true]);
    }

    // ─────────────────────────── helpers ──

    private function find(string $id): ?array
    {
        $row = $this->inv->db()->selectOne('SELECT * FROM `inv_kwitansi` WHERE `id`=?', [$id]);

        return $row ? Invoices::row($row) : null;
    }

    private function signatory(string $id): ?array
    {
        return array_column($this->inv->signatories(true), null, 'id')[$id] ?? null;
    }

    private function callerOnly(Request $r): ?JsonResponse
    {
        $uid = (string) $r->user()->getKey();
        foreach (self::CALLERS as $m) {
            if ($this->access->hasModule($uid, $m)) {
                return null;
            }
        }

        return ApiResponse::error('module_not_granted', 'Needs module finance, reservasi or marketing.', 403);
    }

    private function stale(Request $r, mixed $current): ?JsonResponse
    {
        $h = $r->header('If-Match');
        $v = is_string($h) && $h !== '' ? trim($h, ' "W/') : $r->query('version');
        if (! is_string($v) || $v === '') {
            return ApiResponse::error('version_required', 'Send the version you edited as If-Match (or ?version=).', 428);
        }

        return hash_equals(KasKecilRecords::version($current), $v) ? null
            : ApiResponse::error('version_conflict', 'Changed by someone else. Reload and apply your change again.', 409, ['current' => $current]);
    }

    private function actor(Request $r): string
    {
        return (string) ($this->access->userById((string) $r->user()->getKey())['name'] ?? $r->user()->getKey());
    }

    private static function withVersion(array $data, int $status = 200): JsonResponse
    {
        $v = KasKecilRecords::version($data);

        return ApiResponse::ok($data, ['version' => $v], $status, ['ETag' => '"'.$v.'"']);
    }

    private static function notFound(): JsonResponse
    {
        return ApiResponse::error('not_found', 'Not found.', 404);
    }
}
