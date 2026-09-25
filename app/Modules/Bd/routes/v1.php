<?php

use App\Modules\Bd\Http\V1\BdController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:bd'])->prefix('bd')->group(function () {
    Route::get('state', [C::class, 'state']);
    Route::get('stats', [C::class, 'stats']);

    // settings documents: focus (daily focus per person), approverSets (PR signers), promos
    $docs = 'focus|approverSets|promos';
    Route::get('settings', [C::class, 'documents']);
    Route::get('settings/{key}', [C::class, 'document'])->where('key', $docs);
    Route::put('settings/{key}', [C::class, 'putDocument'])->where('key', $docs);

    // cross-module purchasing writes (Marketing requests, Finance realisation)
    Route::post('purchase-orders/batch', [C::class, 'addPurchaseOrders']);
    Route::put('purchase-orders/{id}/realisasi', [C::class, 'realisasi']);

    $res = 'people|projects|tasks|routines|coord-requests|purchase-orders|purchase-requests|agenda';
    Route::get('{resource}', [C::class, 'index'])->where('resource', $res);
    Route::post('{resource}', [C::class, 'store'])->where('resource', $res);
    Route::get('{resource}/{id}', [C::class, 'show'])->where('resource', $res);
    Route::put('{resource}/{id}', [C::class, 'update'])->where('resource', $res);
    Route::patch('{resource}/{id}', [C::class, 'patch'])->where('resource', $res);
    Route::delete('{resource}/{id}', [C::class, 'destroy'])->where('resource', $res);
});
