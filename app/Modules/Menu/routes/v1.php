<?php

use App\Modules\Menu\Http\V1\MenuController as C;
use App\Modules\Menu\Http\V1\MenuOfficeController as O;
use Illuminate\Support\Facades\Route;

/*
 * Public catalogue — the homepage "Our Best Menu" and the future /menu page.
 * No auth, no server-side cache: Cache-Control + ETag (docs/api/menu.md).
 * The literal paths are declared before `{slug}`.
 */
Route::prefix('menu')->group(function () {
    Route::get('jenis', [C::class, 'jenis']);
    Route::get('unggulan', [C::class, 'unggulan']);
    Route::get('foto/{key}', [C::class, 'foto'])->where('key', '[A-Za-z0-9._-]+');
    Route::get('/', [C::class, 'index']);
    Route::get('{slug}', [C::class, 'show'])->where('slug', '[a-z0-9-]+');
});

/* Office panel: bootstrap, CRUD, the fast toggles, ordering and photos. */
Route::middleware(['auth:sanctum', 'module:menu'])->prefix('menu/office')->group(function () {
    Route::get('state', [O::class, 'state']);

    Route::get('kategori', [O::class, 'kategoriIndex']);
    Route::post('kategori', [O::class, 'kategoriStore']);
    Route::patch('kategori/{id}', [O::class, 'kategoriUpdate']);
    Route::delete('kategori/{id}', [O::class, 'kategoriDestroy']);

    Route::get('item', [O::class, 'itemIndex']);
    Route::post('item', [O::class, 'itemStore']);
    Route::get('item/{id}', [O::class, 'itemShow']);
    Route::patch('item/{id}', [O::class, 'itemUpdate']);
    Route::delete('item/{id}', [O::class, 'itemDestroy']);
    Route::patch('item/{id}/tampil', [O::class, 'itemTampil']);
    Route::patch('item/{id}/tersedia', [O::class, 'itemTersedia']);

    Route::put('urutan', [O::class, 'urutan']);

    Route::post('foto', [O::class, 'fotoUpload']);
    Route::delete('foto/{key}', [O::class, 'fotoDestroy'])->where('key', '[A-Za-z0-9._-]+');
});
