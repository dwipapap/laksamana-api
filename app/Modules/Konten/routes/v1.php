<?php

use App\Modules\Konten\Http\V1\KontenController as C;
use Illuminate\Support\Facades\Route;

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
