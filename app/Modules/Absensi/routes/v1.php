<?php

use App\Modules\Absensi\Http\V1\AbsensiController as C;
use Illuminate\Support\Facades\Route;

// Login first: the PWA keeps the token 30 days as lm_absensi_sesi.
Route::post('absensi/session', [C::class, 'session']);

Route::middleware(['auth:sanctum', 'module:absensi'])->prefix('absensi')->group(function () {
    Route::get('context', [C::class, 'context']);
    Route::post('punches', [C::class, 'punch']);
    Route::get('recap', [C::class, 'recap']);
    Route::get('queue', [C::class, 'queue']);
    Route::post('punches/{id}/decision', [C::class, 'decide']);
    Route::get('faces', [C::class, 'faces']);
    Route::post('faces', [C::class, 'saveFace']);
    Route::delete('faces', [C::class, 'deleteFace']);
    Route::get('locations', [C::class, 'locations']);
    Route::post('locations', [C::class, 'saveLocation']);
    Route::delete('locations/{id}', [C::class, 'deleteLocation']);
    Route::get('settings', [C::class, 'settings']);
    Route::put('settings', [C::class, 'saveSettings']);
});
