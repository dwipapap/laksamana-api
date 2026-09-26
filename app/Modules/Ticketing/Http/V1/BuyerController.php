<?php

namespace App\Modules\Ticketing\Http\V1;

use App\Modules\Ticketing\Http\Legacy\TicketingLegacyController as Legacy;
use App\Modules\Ticketing\Services\TicketBuyers;
use App\Modules\Ticketing\Services\TicketShop;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/tickets Buyer accounts (docs/api/ticketing.md). The session token returned by
 * register / login / reset is the Bearer token of every Buyer-only call.
 */
class BuyerController extends ShopController
{
    public function __construct(
        private readonly TicketShop $tickets,
        private readonly TicketBuyers $buyers,
    ) {
        parent::__construct($tickets);
    }

    /** Body {name, email, phone?, password ≥ 8} → 201 {user, token, pesanan_lama}. */
    public function register(Request $r): JsonResponse
    {
        return $this->run(fn () => $this->buyers->register($r->json()->all()), 201);
    }

    /** Body {email, password} → {user, token}. Throttled per email + caller. */
    public function login(Request $r): JsonResponse
    {
        return $this->run(fn () => $this->buyers->login($r->json()->all()));
    }

    public function logout(Request $r): JsonResponse
    {
        return ApiResponse::ok($this->buyers->logout(Legacy::cleanId($r->bearerToken())));
    }

    public function me(Request $r): JsonResponse
    {
        $u = $this->tickets->buyerFromSession(Legacy::cleanId($r->bearerToken()));

        return $u ? ApiResponse::ok(TicketBuyers::public($u)) : ApiResponse::error('buyer_required', 'Silakan masuk dulu.', 401);
    }

    /** The Buyer's orders, newest first, each with the access token that opens it. */
    public function orders(Request $r): JsonResponse
    {
        return $this->run(fn () => $this->buyers->myTickets($this->tickets->buyerFromSession(Legacy::cleanId($r->bearerToken()))));
    }

    /** Body {email}. Always the same answer, registered or not. */
    public function forgotPassword(Request $r): JsonResponse
    {
        return $this->run(fn () => $this->buyers->forgotPassword($r->json('email', '')));
    }

    /** Body {token, password}: one-time link; every old session is cut → {user, token}. */
    public function resetPassword(Request $r): JsonResponse
    {
        return $this->run(fn () => $this->buyers->resetPassword($r->json('token', ''), $r->json('password', '')));
    }
}
