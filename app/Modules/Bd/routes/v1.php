<?php

use App\Modules\Bd\Http\V1\BdController as C;
use Illuminate\Support\Facades\Route;

// G-24 (#279): Marketing reads its sent purchase needs and files its own.
// The legacy bd-api-mysql getAll/addPo had no module gate; these literal
// routes open to both modules (reads + insert-only batch with server-made
// ids, like legacy tambah_po). They come first so the generic {resource}
// group below (bd-only) never answers them with 403. Realisasi and every
// other BD write stay bd-only.
Route::middleware(['auth:sanctum', 'module:bd|marketing'])->prefix('bd')->group(function () {
    Route::get('purchase-orders', [C::class, 'index'])->defaults('resource', 'purchase-orders');
    Route::get('purchase-orders/{id}', [C::class, 'show'])->defaults('resource', 'purchase-orders');
    Route::post('purchase-orders/batch', [C::class, 'addPurchaseOrders']);
});

Route::middleware(['auth:sanctum', 'module:bd'])->prefix('bd')->group(function () {
    Route::get('state', [C::class, 'state']);
    Route::get('stats', [C::class, 'stats']);

    // settings documents: focus (daily focus per person), approverSets (PR signers), promos
    $docs = 'focus|approverSets|promos';
    Route::get('settings', [C::class, 'documents']);
    Route::get('settings/{key}', [C::class, 'document'])->where('key', $docs);
    Route::put('settings/{key}', [C::class, 'putDocument'])->where('key', $docs);

    // cross-module purchasing writes (Finance realisation; Marketing files
    // its own batch through the G-24 literal route above, same handler)
    Route::put('purchase-orders/{id}/realisasi', [C::class, 'realisasi']);

    $res = 'people|projects|tasks|routines|coord-requests|purchase-orders|purchase-requests|agenda';
    Route::get('{resource}', [C::class, 'index'])->where('resource', $res);
    Route::post('{resource}', [C::class, 'store'])->where('resource', $res);
    Route::get('{resource}/{id}', [C::class, 'show'])->where('resource', $res);
    Route::put('{resource}/{id}', [C::class, 'update'])->where('resource', $res);
    Route::patch('{resource}/{id}', [C::class, 'patch'])->where('resource', $res);
    Route::delete('{resource}/{id}', [C::class, 'destroy'])->where('resource', $res);
});
