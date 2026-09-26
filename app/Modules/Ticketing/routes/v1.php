<?php

use App\Modules\Ticketing\Http\V1\ShopController as C;
use Illuminate\Support\Facades\Route;

// The public ticket shop: Buyers are not Office Users (see ShopController).
Route::prefix('tickets')->group(function () {
    Route::get('events', [C::class, 'events']);
    Route::get('events/{id}', [C::class, 'event']);
    Route::get('events/{id}/seatmap', [C::class, 'seatMap']);
    Route::get('events/{id}/poster', [C::class, 'poster']);

    Route::post('holds', [C::class, 'hold']);
    Route::delete('holds/{token}', [C::class, 'release']);

    Route::post('checkout', [C::class, 'checkout']);
    Route::post('upgrades', [C::class, 'upgrade']);

    Route::get('orders/{ref}', [C::class, 'order']);
    Route::get('orders/{ref}/eticket.pdf', [C::class, 'eticket']);
    Route::post('orders/{ref}/simulate-payment', [C::class, 'simulatePayment']);
});
