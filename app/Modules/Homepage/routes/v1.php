<?php

use App\Modules\Homepage\Http\V1\HomepageController as C;
use App\Modules\Homepage\Http\V1\HomepageOfficeController as O;
use Illuminate\Support\Facades\Route;

/*
 * Office switch screen: the candidate list, the poster preview and the on/off
 * toggle. Sanctum + module:homepage.
 */
Route::middleware(['auth:sanctum', 'module:homepage'])->prefix('homepage/office')->group(function () {
    Route::get('events', [O::class, 'index']);
    Route::get('events/{id}/poster', [O::class, 'poster'])->where('id', '[A-Za-z0-9._-]+');
    Route::patch('events/{id}/tampil', [O::class, 'tampil'])->where('id', '[A-Za-z0-9._-]+');
});

/*
 * Public — the website "Malam" section. No auth: only switched-on, eligible
 * events, and only their poster by event id (never a client-supplied key).
 */
Route::prefix('homepage')->group(function () {
    Route::get('events', [C::class, 'index']);
    Route::get('events/{id}/poster', [C::class, 'poster'])->where('id', '[A-Za-z0-9._-]+');
});
