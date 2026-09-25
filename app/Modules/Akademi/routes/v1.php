<?php

use App\Modules\Akademi\Http\V1\AkademiController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:akademi'])->prefix('akademi')->group(function () {
    // bootstrap + diagnostics + HR read model
    Route::get('state', [C::class, 'state']);
    Route::get('stats', [C::class, 'stats']);
    Route::get('training-stats', [C::class, 'trainingStats']);
    Route::get('training-stats/{user}', [C::class, 'trainingStatsFor']);

    // records: users, divisions, materials, programs (reads for every holder)
    $res = 'users|divisions|materials|programs';
    Route::get('{resource}', [C::class, 'index'])->where('resource', $res);
    Route::get('{resource}/{id}', [C::class, 'show'])->where('resource', $res);

    // progress cells (crew complete materials here) + program cells
    Route::get('progress', [C::class, 'progressIndex']);
    Route::get('progress/{user}/{material}', [C::class, 'progressShow']);
    Route::put('progress/{user}/{material}', [C::class, 'progressPut']);
    Route::delete('progress/{user}/{material}', [C::class, 'progressDelete']);
    Route::get('program-progress', [C::class, 'progProgIndex']);
    Route::put('program-progress/{user}/{program}/{material}', [C::class, 'progProgPut']);
    Route::delete('program-progress/{user}/{program}/{material}', [C::class, 'progProgDelete']);

    // activity trail
    Route::get('activity', [C::class, 'activity']);
    Route::post('activity', [C::class, 'logActivity']);

    // files
    Route::post('files', [C::class, 'upload']);
    Route::get('files/{key}', [C::class, 'file'])->where('key', '[A-Za-z0-9._-]+');

    // settings documents (reads for every holder)
    Route::get('documents/{doc}', [C::class, 'document']);
});

// The Kelola screens are admin-only in the app (Office adminModules), so
// record writes and document writes need module admin rights.
Route::middleware(['auth:sanctum', 'module:akademi,admin'])->prefix('akademi')->group(function () {
    $res = 'users|divisions|materials|programs';
    Route::post('{resource}', [C::class, 'store'])->where('resource', $res);
    Route::put('{resource}/{id}', [C::class, 'update'])->where('resource', $res);
    Route::patch('{resource}/{id}', [C::class, 'patch'])->where('resource', $res);
    Route::delete('{resource}/{id}', [C::class, 'destroy'])->where('resource', $res);

    Route::put('documents/{doc}', [C::class, 'putDocument']);
});
