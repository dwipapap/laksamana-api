<?php

use App\Modules\Homepage\Http\V1\HomepageController as C;
use App\Modules\Homepage\Http\V1\HomepageOfficeController as O;
use App\Modules\Homepage\Http\V1\HomepagePromoController as P;
use App\Modules\Homepage\Http\V1\HomepagePromoOfficeController as OP;
use Illuminate\Support\Facades\Route;

/*
 * Office panels: the Event switch screen and the Promo panel (rows + BD
 * candidates, image previews, uploads, the toggles and the manual order).
 * Sanctum + module:homepage.
 */
Route::middleware(['auth:sanctum', 'module:homepage'])->prefix('homepage/office')->group(function () {
    Route::get('events', [O::class, 'index']);
    Route::get('events/{id}/poster', [O::class, 'poster'])->where('id', '[A-Za-z0-9._-]+');
    Route::patch('events/{id}/tampil', [O::class, 'tampil'])->where('id', '[A-Za-z0-9._-]+');

    Route::get('promos', [OP::class, 'index']);
    Route::get('promos/gambar', [OP::class, 'gambar']);
    Route::post('promos/foto', [OP::class, 'fotoUpload']);
    Route::post('promos', [OP::class, 'store']);
    // literal before {id}, so `urutan` is never read as a row id
    Route::put('promos/urutan', [OP::class, 'urutan']);
    Route::patch('promos/bd/{promoId}/tampil', [OP::class, 'bdTampil'])->where('promoId', '[A-Za-z0-9._-]+');
    Route::patch('promos/{id}', [OP::class, 'update'])->where('id', '[A-Za-z0-9._-]+');
    Route::delete('promos/{id}', [OP::class, 'destroy'])->where('id', '[A-Za-z0-9._-]+');
    Route::patch('promos/{id}/tampil', [OP::class, 'tampil'])->where('id', '[A-Za-z0-9._-]+');
});

/*
 * Public — the website slider. No auth: only switched-on, eligible rows, and
 * their image only by row id (never a client-supplied key or BD data URL).
 */
Route::prefix('homepage')->group(function () {
    Route::get('events', [C::class, 'index']);
    Route::get('events/{id}/poster', [C::class, 'poster'])->where('id', '[A-Za-z0-9._-]+');

    Route::get('promos', [P::class, 'index']);
    Route::get('promos/{id}/gambar', [P::class, 'gambar'])->where('id', '[A-Za-z0-9._-]+');
});
