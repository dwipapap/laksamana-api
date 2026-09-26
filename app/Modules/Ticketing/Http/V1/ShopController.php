<?php

namespace App\Modules\Ticketing\Http\V1;

use App\Modules\Ticketing\Http\Legacy\TicketingLegacyController as Legacy;
use App\Modules\Ticketing\Services\TicketShop;
use App\Support\Api\ApiResponse;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * /api/v1/tickets — the public ticket shop (docs/api/ticketing.md).
 *
 * Buyers are not Office Users, so this is NOT behind auth:sanctum + module:.
 * Reads are public, a hold is owned by its hold token, an order is opened with
 * its ref + access token, and buyer-only writes (checkout, upgrade) take the
 * Buyer session token as `Authorization: Bearer`. Prices are always computed
 * server-side; the same TicketShop serves the legacy route.
 */
class ShopController
{
    public function __construct(private readonly TicketShop $shop) {}

    public function events(): JsonResponse
    {
        return ApiResponse::ok($this->shop->events());
    }

    public function event(string $id): JsonResponse
    {
        $e = $this->shop->event(Legacy::cleanId($id));

        return $e ? ApiResponse::ok($e) : self::notFound('Event tidak ditemukan atau belum dijual.');
    }

    /** Seat map with live status; `?hold=<token>` marks this buyer's own seats `mine`. */
    public function seatMap(Request $r, string $id): JsonResponse
    {
        $e = $this->shop->event(Legacy::cleanId($id));
        if (! $e) {
            return self::notFound('Event tidak ditemukan atau belum dijual.');
        }

        return ApiResponse::ok($this->shop->seatMap($e['id'], Legacy::cleanId($r->query('hold', ''))));
    }

    public function poster(string $id): BaseResponse
    {
        $p = $this->shop->poster(Legacy::cleanId($id));
        if (! $p) {
            return self::notFound('Poster tidak ada.');
        }
        if (isset($p['redirect'])) {
            return redirect()->away($p['redirect']);
        }

        return response()->file($p['file'], ['Content-Type' => $p['type'], 'Cache-Control' => 'public, max-age=86400']);
    }

    /** Body {event_id, seats[≤20], hold_token?}. Refused seats come back in `ditolak`, not as an error. */
    public function hold(Request $r): JsonResponse
    {
        $seats = $r->json('seats');
        if (! is_array($seats) || ! $seats) {
            return self::invalid('Tidak ada kursi yang dipilih.');
        }
        $seats = array_values(array_filter(array_map([Legacy::class, 'cleanId'], array_slice($seats, 0, 20))));
        if (! $seats) {
            return self::invalid('Daftar kursi tidak sah.');
        }

        return $this->run(fn () => $this->shop->hold(Legacy::cleanId($r->json('event_id')), $seats, Legacy::cleanId($r->json('hold_token'))));
    }

    /** DELETE /holds/{token}[?seats=a,b] — only holds not yet bound to an order. */
    public function release(Request $r, string $token): JsonResponse
    {
        $seats = $r->query('seats');
        $seats = is_string($seats) && $seats !== ''
            ? array_values(array_filter(array_map([Legacy::class, 'cleanId'], array_slice(explode(',', $seats), 0, 50))))
            : null;

        return ApiResponse::ok($this->shop->release(Legacy::cleanId($token), $seats));
    }

    /** Body {event_id, hold_token, name, email, phone, notes?, general:[{class_id,qty}]}; Bearer = buyer session. */
    public function checkout(Request $r): JsonResponse
    {
        $b = $r->json()->all();
        $b['sesi'] = (string) $r->bearerToken();
        $b['umum'] = $b['general'] ?? [];
        unset($b['general']);

        return $this->run(fn () => $this->shop->checkout($b), 201);
    }

    /** Body {ticket_id, seat_id}; Bearer = buyer session. Pays only the difference. */
    public function upgrade(Request $r): JsonResponse
    {
        $b = ['sesi' => (string) $r->bearerToken(), 'ticket_id' => $r->json('ticket_id'), 'seat_id' => $r->json('seat_id')];

        return $this->run(fn () => $this->shop->startUpgrade($b), 201);
    }

    /** ?token=<access token> (or X-Order-Token). A Pending order is re-checked with Xendit. */
    public function order(Request $r, string $ref): JsonResponse
    {
        return $this->run(fn () => $this->shop->orderStatus(Legacy::cleanId($ref), self::orderToken($r)));
    }

    public function eticket(Request $r, string $ref): BaseResponse
    {
        try {
            $f = $this->shop->eticketPdf(Legacy::cleanId($ref), self::orderToken($r));
        } catch (Exception $e) {
            return self::fail($e);
        }

        return new Response($f['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$f['name'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Dev only (XENDIT_MOCK, never in production): pays through the same path as the webhook. */
    public function simulatePayment(Request $r, string $ref): JsonResponse
    {
        return $this->run(fn () => $this->shop->simulatePayment(Legacy::cleanId($ref), self::orderToken($r)));
    }

    // ─────────────────────────── helpers ──

    private static function orderToken(Request $r): string
    {
        return Legacy::cleanId($r->header('X-Order-Token') ?: ($r->query('token') ?: $r->json('token', '')));
    }

    private function run(\Closure $fn, int $status = 200): JsonResponse
    {
        if (strlen((string) request()->getContent()) > 256 * 1024) {
            return ApiResponse::error('payload_too_large', 'Permintaan terlalu besar.', 413);
        }
        try {
            return ApiResponse::ok($fn(), [], $status);
        } catch (Exception $e) {
            return self::fail($e);
        }
    }

    /** The shop's own messages are written for the buyer; map them to a status by what they mean. */
    private static function fail(Exception $e): JsonResponse
    {
        if ($e instanceof QueryException || $e instanceof \ErrorException) {
            throw $e;
        }
        $m = $e->getMessage();

        return match (true) {
            str_starts_with($m, 'Silakan masuk dulu') => ApiResponse::error('buyer_required', $m, 401),
            $m === 'Pesanan tidak ditemukan.', $m === 'Tautan tiket tidak sah.', $m === 'Tiket tidak ditemukan.',
            str_starts_with($m, 'Event tidak ditemukan') => self::notFound($m),
            str_starts_with($m, 'Terlalu banyak percobaan') => ApiResponse::error('too_many_attempts', $m, 429),
            $e instanceof RuntimeException && str_starts_with($m, 'Server sedang sibuk') => ApiResponse::error('busy', $m, 503),
            default => ApiResponse::error('rejected', $m, 422),
        };
    }

    private static function notFound(string $m): JsonResponse
    {
        return ApiResponse::error('not_found', $m, 404);
    }

    private static function invalid(string $m): JsonResponse
    {
        return ApiResponse::error('validation_failed', $m, 422);
    }
}
