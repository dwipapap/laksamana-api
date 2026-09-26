<?php

namespace App\Modules\Ticketing\Http\Legacy;

use App\Modules\Ticketing\Services\TicketBuyers;
use App\Modules\Ticketing\Services\TicketMail;
use App\Modules\Ticketing\Services\TicketShop;
use App\Support\Legacy\Envelope;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stand-in for ticketing-mysql/api.php — `/ticketing-api/api.php`, the public shop.
 *
 * Not a LegacyController: this api.php had its own front door, reproduced here.
 * - no shared API_TOKEN; every action is public (the narrow endpoints and the
 *   per-order access token are the guard)
 * - POST bodies over 256 KB → 413, JSON nesting capped at 16
 * - a POST with an x-callback-token header and no action is the Xendit webhook
 * - ids pass idBersih() (letters, digits, `._-`, ≤128)
 * - no-store / nosniff / DENY on every answer
 * - failures are {ok:false,error} with HTTP 200, except the webhook (401 wrong
 *   token, 500 anything else, so Xendit retries); DB and PHP errors are never
 *   told to the public
 *
 * Buyer accounts ride in the body (POST `sesi`) or `?sesi=` (GET): no cookies, the
 * page may live on another domain.
 */
class TicketingLegacyController
{
    private const SAFE_HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
    ];

    public function __construct(
        private readonly TicketShop $shop,
        private readonly TicketBuyers $buyers,
        private readonly TicketMail $mail,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $this->secure($this->handle($request));
    }

    private function secure(Response $r): Response
    {
        foreach (self::SAFE_HEADERS as $k => $v) {
            // the poster alone is cacheable
            if ($k !== 'Cache-Control' || ! str_contains((string) $r->headers->get($k), 'public')) {
                $r->headers->set($k, $v);
            }
        }

        return $r;
    }

    public static function cleanId($v, int $max = 128): string
    {
        if (is_array($v) || is_object($v)) {
            return '';
        }
        $v = trim((string) $v);
        if ($v === '' || strlen($v) > $max) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9._\-]+$/', $v) ? $v : '';
    }

    private function handle(Request $request): Response
    {
        $post = $request->isMethod('POST');
        $body = [];
        if ($post) {
            $raw = (string) $request->getContent();
            if (strlen($raw) > 256 * 1024) {
                return Envelope::error('Permintaan terlalu besar.', 413);
            }
            $body = json_decode($raw, true, 16);
            if (! is_array($body)) {
                $body = [];
            }
        }
        // $_GET exactly (no TrimStrings / empty-to-null)
        parse_str((string) $request->server('QUERY_STRING', ''), $get);
        $G = fn (string $k, $d = '') => (($v = $get[$k] ?? $d) !== null && is_array($v)) ? $d : $v;
        $B = fn (string $k, $d = '') => $body[$k] ?? $d;

        $action = $post ? ($body['action'] ?? '') : ($get['action'] ?? 'events');
        $action = is_array($action) ? 'Array' : (string) $action;
        $hdr = (string) $request->header('x-callback-token', '');
        if ($post && $hdr !== '' && $action === '') {
            $action = 'webhook';
        }

        try {
            return match ($action) {
                'ping' => Envelope::json(['ok' => true, 'data' => array_merge(
                    ['pong' => true, 'backend' => 'laravel'], $this->shop->identity(), ['ts' => gmdate('c')])]),
                'events' => Envelope::okData($this->shop->events()),
                'event' => ($e = $this->shop->event(self::cleanId($G('id'))))
                    ? Envelope::okData($e)
                    : Envelope::error('Event tidak ditemukan atau belum dijual.'),
                'poster' => $this->poster(self::cleanId($G('id'))),
                'denah' => Envelope::okData($this->shop->seatMap(self::cleanId($G('id')), self::cleanId($G('hold')))),
                'hold' => $this->hold($B),
                'release' => $this->release($B),
                'upgradeMulai' => Envelope::okData($this->shop->startUpgrade($body)),
                'checkout' => Envelope::okData($this->shop->checkout($body)),
                'webhook' => Envelope::okData($this->shop->webhook($body, $hdr)),
                'simbayar' => Envelope::okData($this->shop->simulatePayment(self::cleanId($B('ref')), self::cleanId($B('token')))),
                'order' => Envelope::okData($this->shop->orderStatus(self::cleanId($G('ref')), self::cleanId($G('token')))),
                'daftar' => Envelope::okData($this->buyers->register($body)),
                'masuk' => Envelope::okData($this->buyers->login($body)),
                'keluar' => Envelope::okData($this->buyers->logout(self::cleanId($B('sesi')))),
                'lupaPassword' => Envelope::okData($this->buyers->forgotPassword($B('email'))),
                'resetPassword' => Envelope::okData($this->buyers->resetPassword($B('token'), $B('password'))),
                'saya' => Envelope::okData(TicketBuyers::public($this->shop->buyerFromSession(self::cleanId($G('sesi'))))),
                'tiketSaya' => Envelope::okData($this->buyers->myTickets($this->shop->buyerFromSession(self::cleanId($G('sesi'))))),
                'ujiEmail' => $this->testMail((string) $G('ke')),
                default => Envelope::error('Aksi tidak dikenal: '.$action),
            };
        } catch (Throwable $e) {
            $status = 200;
            if ($action === 'webhook') {
                $wrongToken = str_contains($e->getMessage(), 'callback') || str_contains($e->getMessage(), 'Token');
                $status = $wrongToken ? 401 : 500;
            }
            // our own Exceptions are written for the buyer; DB and PHP errors are logged, never shown
            $internal = ! $e instanceof Exception || $e instanceof QueryException || $e instanceof \ErrorException;
            if ($internal) {
                Log::error('[ticketing] '.$e->getMessage());
            }

            return Envelope::error($internal ? 'Terjadi gangguan di server. Coba lagi sebentar lagi.' : $e->getMessage(), $status);
        }
    }

    private function hold(\Closure $B): Response
    {
        $seats = $B('seats', []);
        if (! is_array($seats) || ! $seats) {
            return Envelope::error('Tidak ada kursi yang dipilih.');
        }
        // at most 20 seats, each id checked: a huge list would hold the lock for everyone
        $seats = array_values(array_filter(array_map([self::class, 'cleanId'], array_slice($seats, 0, 20))));
        if (! $seats) {
            return Envelope::error('Daftar kursi tidak sah.');
        }

        return Envelope::okData($this->shop->hold(self::cleanId($B('event_id')), $seats, self::cleanId($B('hold_token'))));
    }

    private function release(\Closure $B): Response
    {
        $s = $B('seats', null);
        if (is_array($s)) {
            $s = array_values(array_filter(array_map([self::class, 'cleanId'], array_slice($s, 0, 50))));
        }

        return Envelope::okData($this->shop->release(self::cleanId($B('hold_token')), is_array($s) ? $s : null));
    }

    /** SMTP self-test before a real Buyer depends on it; never in production (it would mail as our domain). */
    private function testMail(string $to): Response
    {
        if (TicketShop::env() === 'produksi') {
            return Envelope::error('Uji email tidak tersedia di produksi.');
        }
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return Envelope::error('Isi ?ke= dengan alamat email lengkap, mis. ?ke=nama@gmail.com (dapat: "'.$to.'").');
        }
        $this->mail->send($to, 'Uji kirim Laksamana Muda Ticketing', '<p>Kalau email ini sampai, SMTP sudah benar.</p>');

        return Envelope::okData(['terkirim_ke' => $to]);
    }

    /** Not JSON: the image itself (cacheable), a redirect to the EMS API, or an empty 404. */
    private function poster(string $id): Response
    {
        $p = $this->shop->poster($id);
        if (! $p) {
            return new \Illuminate\Http\Response('', 404, ['Content-Type' => 'application/json; charset=utf-8']);
        }
        if (isset($p['redirect'])) {
            return new \Illuminate\Http\Response('', 302, ['Location' => $p['redirect'], 'Content-Type' => 'application/json; charset=utf-8']);
        }

        return new \Illuminate\Http\Response(file_get_contents($p['file']), 200, [
            'Content-Type' => $p['type'],
            'Content-Length' => (string) filesize($p['file']),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
