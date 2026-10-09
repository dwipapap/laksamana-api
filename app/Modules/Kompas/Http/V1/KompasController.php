<?php

namespace App\Modules\Kompas\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Kompas\Services\KompasConflict;
use App\Modules\Kompas\Services\KompasState;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/kompas — revenue core (see docs/api/kompas.md).
 *
 * The omset blob (Input Omset Harian, Cashier) has ONE version: its
 * `updated_at`. A whole-blob PUT must send it (If-Match / ?version=); the
 * NARROW writes (targets, rekap) merge safely under the same lock, so their
 * version is optional — when sent it is checked. The author is always the
 * session user.
 *
 * Access: the blob and the narrow writes need any of kompas / finance /
 * cashier; omset-pic needs marketing or finance; performa/{divisi} needs the
 * module of that division (as legacy performaDivisi).
 */
class KompasController
{
    private const OMSET = ['kompas', 'finance', 'cashier'];

    public function __construct(
        private readonly KompasState $kompas,
        private readonly OfficeAccess $access,
    ) {}

    public function state(Request $r): JsonResponse
    {
        if ($denied = $this->anyOf($r, self::OMSET)) {
            return $denied;
        }
        $ts = $this->kompas->ts();

        return ApiResponse::ok($this->kompas->read(), ['version' => $ts], 200, ['ETag' => '"'.$ts.'"']);
    }

    /** Body {data: <the whole blob>}; If-Match required. */
    public function putState(Request $r): JsonResponse
    {
        if ($denied = $this->anyOf($r, self::OMSET)) {
            return $denied;
        }
        $base = self::baseVersion($r);
        if ($base === null) {
            return ApiResponse::error('version_required', 'Send the version (updated_at) you loaded as If-Match (or ?version=).', 428);
        }
        $data = $r->json('data');
        if (! is_array($data)) {
            return ApiResponse::error('validation_failed', 'Send {"data": {...}}.', 422);
        }
        $data['_savedBy'] = $this->actor($r);
        // an explicit 0 would skip the guard in legacy; v1 always checks
        $out = $this->kompas->saveAll($data, $base === 0 ? PHP_INT_MAX : $base);
        if (! empty($out['konflik'])) {
            return self::conflict($out['ts'], $out['by']);
        }

        return ApiResponse::ok(['saved' => true], ['version' => $out['ts']], 200, ['ETag' => '"'.$out['ts'].'"']);
    }

    /** Body {companyMonthlyTarget?, useWorkingDays?, workingDaysPerMonth?, target?: {picId: n}}. */
    public function putTargets(Request $r): JsonResponse
    {
        return $this->narrow($r, fn ($data, $base) => $this->kompas->saveTarget($data, $base));
    }

    /** Body {hari: {date: {setor?, mdr?, aktual?, mdrManual?, esb?}}, setoran?: {tambah?: [...], hapus?: [id]}}. */
    public function putRekap(Request $r): JsonResponse
    {
        return $this->narrow($r, function ($data, $base) {
            // v1 treats a missing `hari` as "touch no days"; legacy simpanRekap
            // must keep requiring it, so default here instead of the service.
            if (! array_key_exists('hari', $data)) {
                $data['hari'] = [];
            }

            return $this->kompas->saveRekap($data, $base);
        });
    }

    private function narrow(Request $r, \Closure $write): JsonResponse
    {
        if ($denied = $this->anyOf($r, self::OMSET)) {
            return $denied;
        }
        $data = $r->json()->all();
        $data['by'] = $this->actor($r);
        try {
            $out = $write($data, self::baseVersion($r));
        } catch (KompasConflict $e) {
            return self::conflict($e->current, null);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }
        unset($out['ts']);
        $ts = $this->kompas->ts();

        return ApiResponse::ok($out, ['version' => $ts], 200, ['ETag' => '"'.$ts.'"']);
    }

    // ─────────────────────────── granular parts of the blob ──

    /** GET sections/{key} | reports/{date} | days/{date} — one part with its own version (content hash). */
    public function part(Request $r, string $key): JsonResponse
    {
        $kind = (string) $r->route('kind');
        if ($denied = $this->anyOf($r, self::OMSET) ?? $this->badKey($kind, $key)) {
            return $denied;
        }
        $v = KompasState::part($this->kompas->assoc(), $kind, $key);

        return ApiResponse::ok($v, ['version' => KompasState::partVersion($v)], 200, ['ETag' => '"'.KompasState::partVersion($v).'"']);
    }

    /**
     * PUT one part. Body {value} for a section or a Daily Report (null removes
     * it), {rows: [...]} for the daily rows of one date. If-Match = that part's
     * version: edits to OTHER days/sections never conflict.
     */
    public function putPart(Request $r, string $key): JsonResponse
    {
        $kind = (string) $r->route('kind');
        if ($denied = $this->anyOf($r, self::OMSET) ?? $this->badKey($kind, $key)) {
            return $denied;
        }
        $h = $r->header('If-Match');
        $base = is_string($h) && $h !== '' ? trim($h, ' "W/') : $r->query('version');
        if (! is_string($base) || $base === '') {
            return ApiResponse::error('version_required', 'Send the version of this part as If-Match (or ?version=).', 428);
        }
        $body = $r->json()->all();
        $field = $kind === 'day' ? 'rows' : 'value';
        if (! array_key_exists($field, $body) || ($kind === 'day' && ! is_array($body['rows']))) {
            return ApiResponse::error('validation_failed', "Send {\"$field\": …}.", 422);
        }
        try {
            $out = $this->kompas->savePart($kind, $key, $body[$field], $base, $this->actor($r));
        } catch (KompasConflict $e) {
            return ApiResponse::error('version_conflict', 'This part was changed by someone else. Reload it and apply your change again.', 409,
                ['current' => KompasState::part($this->kompas->assoc(), $kind, $key)]);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_request', $e->getMessage(), 422);
        }

        return ApiResponse::ok($out['value'], ['version' => $out['version'], 'blobVersion' => $out['ts']], 200, ['ETag' => '"'.$out['version'].'"']);
    }

    private function badKey(string $kind, string $key): ?JsonResponse
    {
        $ok = $kind === 'section' ? (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/', $key) && $key !== '_savedBy'
            : KompasState::tgl($key) !== null;

        return $ok ? null : ApiResponse::error('validation_failed', 'Unknown section or date (YYYY-MM-DD).', 422);
    }

    /** Daily revenue from THE daily map, with the three conventions (net / tagihan / netSales). */
    public function daily(Request $r): JsonResponse
    {
        if ($denied = $this->anyOf($r, self::OMSET)) {
            return $denied;
        }
        $f = $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $out = [];
        foreach ($this->kompas->dailyMap() as $date => $v) {
            if ((isset($f['from']) && $date < $f['from']) || (isset($f['to']) && $date > $f['to'])) {
                continue;
            }
            $out[] = ['date' => $date, 'food' => $v[0], 'bev' => $v[1], 'lainnya' => $v[2], 'discount' => $v[3], 'service' => $v[4],
                'tax' => $v[5], 'bill' => $v[6], 'compliment' => $v[7],
                'net' => KompasState::net($v), 'tagihan' => KompasState::tagihan($v), 'netSales' => KompasState::netSales($v)];
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return ApiResponse::ok($out, ['total' => count($out)]);
    }

    /** Section B (marketing breakdown) per PIC, as Finance recognises it. */
    public function omsetPic(Request $r): JsonResponse
    {
        if ($denied = $this->anyOf($r, ['marketing', 'finance'])) {
            return $denied;
        }

        return $this->range($r, fn ($from, $to) => $this->kompas->omsetPic($from, $to));
    }

    /** Raw inputs of the bonus calculator for one division; gated by that division's module. */
    public function performa(Request $r, string $divisi): JsonResponse
    {
        if ($denied = $this->anyOf($r, [$divisi])) {
            return $denied;
        }

        return $this->range($r, fn ($from, $to) => $this->kompas->performaDivisi($divisi, $from, $to));
    }

    // ─────────────────────────── helpers ──

    private function range(Request $r, \Closure $fn): JsonResponse
    {
        try {
            return ApiResponse::ok($fn((string) $r->query('from', ''), (string) $r->query('to', '')));
        } catch (RuntimeException $e) {
            return ApiResponse::error('validation_failed', $e->getMessage(), 422);
        }
    }

    private function anyOf(Request $r, array $modules): ?JsonResponse
    {
        $uid = (string) $r->user()->getKey();
        foreach ($modules as $m) {
            if ($this->access->hasModule($uid, $m)) {
                return null;
            }
        }

        return ApiResponse::error('module_not_granted', 'Needs one of the modules: '.implode(', ', $modules).'.', 403);
    }

    private function actor(Request $r): string
    {
        return (string) ($this->access->userById((string) $r->user()->getKey())['name'] ?? $r->user()->getKey());
    }

    private static function baseVersion(Request $r): ?int
    {
        $h = $r->header('If-Match');
        $v = is_string($h) && $h !== '' ? trim($h, ' "W/') : $r->query('version');

        return is_string($v) && ctype_digit($v) ? (int) $v : null;
    }

    private static function conflict(int $current, ?string $by): JsonResponse
    {
        return ApiResponse::error('version_conflict', 'The omset data was saved by someone else. Reload and apply your change again.', 409,
            array_filter(['version' => $current, 'savedBy' => $by], fn ($x) => $x !== null));
    }
}
