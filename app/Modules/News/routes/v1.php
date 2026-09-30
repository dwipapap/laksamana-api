<?php

use App\Modules\News\Http\V1\NewsController as C;
use App\Modules\News\Http\V1\NewsOfficeController as O;
use Illuminate\Support\Facades\Route;

/* Office panel: bootstrap, CRUD, the publish toggle, category order and covers. */
Route::middleware(['auth:sanctum', 'module:news'])->prefix('news/office')->group(function () {
    Route::get('state', [O::class, 'state']);

    Route::get('kategori', [O::class, 'kategoriIndex']);
    Route::post('kategori', [O::class, 'kategoriStore']);
    Route::patch('kategori/{id}', [O::class, 'kategoriUpdate']);
    Route::delete('kategori/{id}', [O::class, 'kategoriDestroy']);

    Route::get('artikel', [O::class, 'artikelIndex']);
    Route::post('artikel', [O::class, 'artikelStore']);
    Route::get('artikel/{id}', [O::class, 'artikelShow']);
    Route::patch('artikel/{id}', [O::class, 'artikelUpdate']);
    Route::delete('artikel/{id}', [O::class, 'artikelDestroy']);
    Route::patch('artikel/{id}/tampil', [O::class, 'artikelTampil']);

    Route::put('urutan', [O::class, 'urutan']);

    Route::post('foto', [O::class, 'fotoUpload']);
    Route::delete('foto/{key}', [O::class, 'fotoDestroy'])->where('key', '[A-Za-z0-9._-]+');
});

/*
 * Public — the homepage "Kabar dari Laksamana" rail, /news and /news/{slug}.
 * No auth, no server-side cache: Cache-Control + ETag. Literal paths before
 * `{slug}`; slugs are [a-z0-9-] so `office` never reaches it with a slash.
 */
Route::prefix('news')->group(function () {
    Route::get('kategori', [C::class, 'kategori']);
    Route::get('foto/{key}', [C::class, 'foto'])->where('key', '[A-Za-z0-9._-]+');
    Route::get('/', [C::class, 'index']);
    Route::get('{slug}', [C::class, 'show'])->where('slug', '[a-z0-9-]+');
});
