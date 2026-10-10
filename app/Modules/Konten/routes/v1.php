<?php

use App\Modules\Konten\Http\V1\KontenAdminController as AD;
use App\Modules\Konten\Http\V1\KontenController as C;
use Illuminate\Support\Facades\Route;

// Design options for Marketing's Request Design form (G-16): brands and active
// crew only, none of the other Konten state. The legacy form read these straight
// from konten getAll; this narrow read opens to both modules.
Route::middleware(['auth:sanctum', 'module:konten|marketing'])->prefix('konten')->group(function () {
    Route::get('design-options', [C::class, 'designOptions']);
});

Route::middleware(['auth:sanctum', 'module:konten'])->prefix('konten')->group(function () {
    // bootstrap + diagnostics
    Route::get('state', [C::class, 'state']);
    Route::get('stats', [C::class, 'stats']);

    // activity trail
    Route::get('logs', [C::class, 'logs']);
    Route::post('logs', [C::class, 'logActivity']);

    // settings documents (settings, perms, seeded)
    Route::get('documents/{doc}', [C::class, 'document']);
    Route::put('documents/{doc}', [C::class, 'putDocument']);

    // files
    Route::post('files', [C::class, 'upload']);
    Route::get('files/{key}', [C::class, 'file'])->where('key', '[A-Za-z0-9._-]+');

    // per-platform performance figures living inside the content row
    Route::put('content/{id}/performance', [C::class, 'performance']);

    // records: users, brands, campaigns, content, prod-tasks, shootings,
    // assets, bank, kols, visits, ads, ad-funds, notifications
    $res = 'users|brands|campaigns|content|prod-tasks|shootings|assets|bank|kols|visits|ads|ad-funds|notifications';
    Route::get('{resource}', [C::class, 'index'])->where('resource', $res);
    Route::post('{resource}', [C::class, 'store'])->where('resource', $res);
    Route::get('{resource}/{id}', [C::class, 'show'])->where('resource', $res);
    Route::put('{resource}/{id}', [C::class, 'update'])->where('resource', $res);
    Route::patch('{resource}/{id}', [C::class, 'patch'])->where('resource', $res);
    Route::delete('{resource}/{id}', [C::class, 'destroy'])->where('resource', $res);
});

// G-13 (#187): restore the whole database from a backup file — module admin, typed confirmation PULIHKAN.
Route::middleware(['auth:sanctum', 'module:konten,admin'])->post('konten/admin/restore', [AD::class, 'restore']);
