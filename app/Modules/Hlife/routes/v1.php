<?php

use App\Modules\Hlife\Http\V1\HlifeAdminController as AD;
use App\Modules\Hlife\Http\V1\HlifeController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:howandi_life'])->prefix('hlife')->group(function () {
    Route::get('state', [C::class, 'state']);
    Route::get('stats', [C::class, 'stats']);

    $keys = 'firstRun|mood|energy|focus|weeklyTarget|auth|channels|dump';
    Route::get('settings', [C::class, 'settings']);
    Route::get('settings/{key}', [C::class, 'setting'])->where('key', $keys);
    Route::put('settings/{key}', [C::class, 'putSetting'])->where('key', $keys);

    $res = 'businesses|projects|tasks|goals|dreams|roadmap|content|learning|habits|events|assets|reviews|ledger';
    Route::get('{resource}', [C::class, 'index'])->where('resource', $res);
    Route::post('{resource}', [C::class, 'store'])->where('resource', $res);
    Route::get('{resource}/{id}', [C::class, 'show'])->where('resource', $res);
    Route::put('{resource}/{id}', [C::class, 'update'])->where('resource', $res);
    Route::patch('{resource}/{id}', [C::class, 'patch'])->where('resource', $res);
    Route::delete('{resource}/{id}', [C::class, 'destroy'])->where('resource', $res);
});

// G-13 (#187): "Reset data" — module admin, typed confirmation KOSONGKAN.
Route::middleware(['auth:sanctum', 'module:howandi_life,admin'])->post('hlife/admin/reset', [AD::class, 'reset']);
