<?php

namespace App\Modules\Ticketing\Services;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The two Xendit calls of legacy lib_ticketing.php (POST /v2/invoices, GET
 * /v2/invoices/{id}) and the key/mock checks around them. Through Laravel's
 * Http client, so tests fake it: no test ever reaches the real Xendit.
 */
class Xendit
{
    private const BASE = 'https://api.xendit.co/v2/invoices';

    private static function secret(): string
    {
        return (string) config('laksamana.ticketing.xendit_secret', '');
    }

    /** Legacy xendit_mode(): what the key looks like, never the key itself. */
    public static function mode(): string
    {
        $s = config('laksamana.ticketing.xendit_secret');
        if ($s === null) {
            return 'belum diisi (baris XENDIT_SECRET tidak ada)';
        }
        $s = (string) $s;
        if (str_starts_with($s, 'ISI_')) {
            return 'belum diisi (masih teks contoh — ada define ganda?)';
        }
        if ($s === '') {
            return 'belum diisi (kosong)';
        }
        if (str_starts_with($s, 'xnd_production')) {
            return 'LIVE';
        }
        if (str_starts_with($s, 'xnd_development')) {
            return 'test';
        }

        return 'terisi, tapi awalannya bukan xnd_development/xnd_production';
    }

    public static function unset(): bool
    {
        return str_starts_with(self::mode(), 'belum diisi');
    }

    /** GET /v2/invoices/{id}; null when Xendit cannot be reached (NOT the same as unpaid). */
    public static function invoice(string $id): ?array
    {
        try {
            $r = Http::withBasicAuth(self::secret(), '')->timeout(20)->get(self::BASE.'/'.rawurlencode($id));
        } catch (ConnectionException) {
            return null;
        }
        if ($r->status() >= 300) {
            return null;
        }
        $d = $r->json();

        return is_array($d) ? $d : [];
    }

    /** POST /v2/invoices; throws the legacy messages on failure. */
    public static function create(array $body): array
    {
        try {
            $r = Http::withBasicAuth(self::secret(), '')->timeout(30)->asJson()->post(self::BASE, $body);
        } catch (ConnectionException $e) {
            throw new Exception('Gagal menghubungi Xendit: '.$e->getMessage());
        }
        $d = $r->json();
        if ($r->status() >= 300 || ! isset($d['invoice_url'])) {
            throw new Exception('Xendit menolak: '.($d['message'] ?? substr($r->body(), 0, 200)));
        }

        return $d;
    }
}
