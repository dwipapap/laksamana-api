<?php

use App\Modules\Hris\Http\V1\HrisBalanceController as B;
use App\Modules\Hris\Http\V1\HrisCalendarController as K;
use App\Modules\Hris\Http\V1\HrisFaceController as F;
use App\Modules\Hris\Http\V1\HrisMeController as M;
use App\Modules\Hris\Http\V1\HrisOfficeController as O;
use App\Modules\Hris\Http\V1\HrisQueueController as Q;
use App\Modules\Hris\Http\V1\HrisRequestController as R;
use Illuminate\Support\Facades\Route;

/*
 * HRIS (Talenta replacement, greenfield on `core`). Sanctum + module:hris;
 * Lingkup (sendiri/divisi/semua) is enforced per endpoint in the services.
 */
Route::middleware(['auth:sanctum', 'module:hris'])->prefix('hris')->group(function () {
    // Crew surface.
    Route::get('me/today', [M::class, 'today']);
    Route::post('punches', [M::class, 'punch']);
    Route::get('punches', [M::class, 'punches']);
    Route::get('punches/{id}/photo', [M::class, 'punchPhoto'])->where('id', '[A-Za-z0-9-]+');

    // Decision queue (HR + Kepala Divisi).
    Route::get('queue', [Q::class, 'queue']);
    Route::post('punches/{id}/decision', [Q::class, 'decide'])->where('id', '[A-Za-z0-9-]+');

    // Faces (A3).
    Route::post('faces/me', [F::class, 'enrolMe']);
    Route::get('faces', [F::class, 'index']);
    Route::get('faces/{userId}/photo', [F::class, 'photo'])->where('userId', '[A-Za-z0-9._-]+');
    Route::delete('faces/{userId}', [F::class, 'reset'])->where('userId', '[A-Za-z0-9._-]+');

    // HR office: locations, settings, one-time import.
    Route::get('locations', [O::class, 'locations']);
    Route::post('locations', [O::class, 'saveLocation']);
    Route::patch('locations/{id}', [O::class, 'patchLocation'])->where('id', '[A-Za-z0-9-]+');
    Route::delete('locations/{id}', [O::class, 'deleteLocation'])->where('id', '[A-Za-z0-9-]+');
    Route::get('settings', [O::class, 'settings']);
    Route::put('settings', [O::class, 'saveSettings']);
    Route::post('admin/import-locations', [O::class, 'importLocations']);

    // Leave, PH and requests (B2: L1–L9).
    Route::get('calendar', [K::class, 'index']);
    Route::post('calendar', [K::class, 'store']);
    Route::delete('calendar/{id}', [K::class, 'destroy'])->where('id', '[A-Za-z0-9-]+');
    Route::post('calendar/import', [K::class, 'import']);

    Route::get('balances/me', [B::class, 'me']);
    Route::get('balances', [B::class, 'index']);
    Route::post('balances/adjust', [B::class, 'adjust']);

    Route::get('requests', [R::class, 'index']);
    Route::post('requests', [R::class, 'store']);
    Route::get('requests/{id}', [R::class, 'show'])->where('id', '[A-Za-z0-9-]+');
    Route::get('requests/{id}/lampiran', [R::class, 'lampiran'])->where('id', '[A-Za-z0-9-]+');
    Route::delete('requests/{id}', [R::class, 'destroy'])->where('id', '[A-Za-z0-9-]+');
    Route::post('requests/{id}/decision', [R::class, 'decide'])->where('id', '[A-Za-z0-9-]+');
});
