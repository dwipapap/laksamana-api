<?php

namespace App\Modules\Kompas\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Port of kompas-mysql/lib_esb.php (esb_halaman): ONE page of POS ESB sales per
 * call. The caller (Analytics "Sinkronkan dengan ESB", Cashier "Isi POS dari
 * ESB") loops the pages itself — a whole month is ~270 pages and does not fit
 * one PHP request. Nothing is written: the raw bills go back to the browser,
 * which parses them with the same parser as the Excel upload.
 *
 * The token lives in server config only (services.esb.token). It opens every
 * sale of the company, so it is never part of any response.
 */
class EsbSales
{
    public const DEFAULT_URL = 'https://core-api.esb.co.id/corev1/sales/sales-information';

    /**
     * {ok: true, data: {bills, page, pageCount, totalCount, perPage}} or
     * {ok: false, code, status, error}.
     *
     * @return array<string, mixed>
     */
    public function page(string $dari, string $sampai, mixed $page): array
    {
        $token = trim((string) config('services.esb.token'));
        if ($token === '') {
            return self::fail('esb_not_configured', 503, 'Token ESB belum dipasang di server (ESB_TOKEN).');
        }
        if (! self::validDate($dari) || ! self::validDate($sampai)) {
            return self::fail('invalid_request', 422, 'Tanggal tidak sah (harus YYYY-MM-DD).');
        }
        if ($dari > $sampai) {
            return self::fail('invalid_request', 422, 'Tanggal awal sesudah tanggal akhir.');
        }
        $page = filter_var($page, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 20000) {
            return self::fail('invalid_request', 422, 'Nomor halaman tidak sah.');
        }
        // accepted with or without the "Bearer " prefix, like the legacy config
        if (stripos($token, 'bearer ') === 0) {
            $token = trim(substr($token, 7));
        }
        $url = trim((string) config('services.esb.url')) ?: self::DEFAULT_URL;

        try {
            $res = Http::withToken($token)->acceptJson()->connectTimeout(15)->timeout(45)
                ->get($url, ['salesDateFrom' => $dari, 'salesDateTo' => $sampai, 'page' => $page]);
        } catch (ConnectionException $e) {
            return self::fail('esb_error', 502, 'ESB tidak terhubung: '.$e->getMessage());
        }

        $code = $res->status();
        // 401/403 are told apart: the fix is a new token, not a retry
        if ($code === 401 || $code === 403) {
            return self::fail('esb_token_rejected', 502, "Token ESB ditolak (HTTP $code). Token di server salah atau sudah kedaluwarsa.");
        }
        $d = $res->json();
        if ($code >= 400 || ! is_array($d)) {
            $msg = is_array($d) && isset($d['message']) ? (string) $d['message'] : substr($res->body(), 0, 200);

            return self::fail('esb_error', 502, "ESB menjawab HTTP $code: $msg");
        }

        // a bare list is Yii2 style (pagination in the headers); otherwise look in common keys
        $bills = null;
        $meta = [];
        if (array_is_list($d)) {
            $bills = $d;
        } else {
            foreach (['data', 'items', 'result', 'results'] as $k) {
                if (isset($d[$k]) && is_array($d[$k])) {
                    $bills = $d[$k];
                    break;
                }
            }
            $meta = is_array($d['_meta'] ?? null) ? $d['_meta'] : (is_array($d['meta'] ?? null) ? $d['meta'] : $d);
        }
        if (! is_array($bills)) {
            $bills = [];
        }

        $h = fn (string $name) => $res->header($name) !== '' ? (int) $res->header($name) : null;

        return ['ok' => true, 'data' => [
            'bills' => array_values($bills),
            'page' => $page,
            // not found = null: the caller stops at the first empty page instead of guessing
            'pageCount' => $h('X-Pagination-Page-Count') ?? self::number($meta, ['pageCount', 'page_count', 'totalPage', 'totalPages', 'total_page', 'lastPage', 'last_page']),
            'totalCount' => $h('X-Pagination-Total-Count') ?? self::number($meta, ['totalCount', 'total_count', 'total', 'totalData', 'total_data']),
            'perPage' => $h('X-Pagination-Per-Page') ?? self::number($meta, ['perPage', 'per_page', 'pageSize', 'limit']),
        ]];
    }

    private static function validDate(string $t): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function number(array $a, array $keys): ?int
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && is_numeric($a[$k])) {
                return (int) $a[$k];
            }
        }

        return null;
    }

    private static function fail(string $code, int $status, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'status' => $status, 'error' => $error];
    }
}
