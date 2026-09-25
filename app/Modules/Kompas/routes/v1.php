<?php

use App\Modules\Kompas\Http\V1\KompasController as C;
use App\Modules\Kompas\Http\V1\VoidBriController as V;
use Illuminate\Support\Facades\Route;

// Module checks are per endpoint (several modules read kompas): see KompasController.
Route::middleware('auth:sanctum')->prefix('kompas')->group(function () {
    Route::get('state', [C::class, 'state']);
    Route::put('state', [C::class, 'putState']);
    Route::put('targets', [C::class, 'putTargets']);
    Route::put('rekap', [C::class, 'putRekap']);
    Route::get('daily', [C::class, 'daily']);
    Route::get('omset-pic', [C::class, 'omsetPic']);
    Route::get('performa/{divisi}', [C::class, 'performa'])->whereIn('divisi', ['marketing', 'event']);

    // Catatan Void & QRIS BRI matching (module cashier or finance, checked in the controller)
    Route::get('voids', [V::class, 'voids']);
    Route::post('voids', [V::class, 'createVoid']);
    Route::get('voids/settings', [V::class, 'voidSetting']);
    Route::put('voids/settings', [V::class, 'putVoidSetting']);
    Route::put('voids/{id}', [V::class, 'updateVoid']);
    Route::post('voids/{id}/cancel', [V::class, 'cancelVoid']);
    Route::get('bri', [V::class, 'bri']);
    Route::post('bri', [V::class, 'addManual']);
    Route::post('bri/upload', [V::class, 'upload']);
    Route::post('bri/match', [V::class, 'match']);
    Route::post('bri/ignored', [V::class, 'ignoreDp']);
    Route::delete('bri/ignored/{dpId}', [V::class, 'unignoreDp']);
    Route::post('bri/{id}/cancel', [V::class, 'cancelMutation']);
});
