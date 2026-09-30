<?php

use App\Modules\Radar\Http\V1\RadarController as C;
use Illuminate\Support\Facades\Route;

// Radar only reads (deploy/radar: "Radar hanya membaca"). Module `radar` is
// built in for every account (OfficeAccess::builtinModules), so this contract
// is narrower than the source modules': see RadarBoard.
Route::middleware(['auth:sanctum', 'module:radar'])->prefix('radar')->group(function () {
    Route::get('board', [C::class, 'board']);
    Route::get('agenda/{sumber}/{id}', [C::class, 'detail'])->whereIn('sumber', ['mkt', 'evt', 'vip']);
    Route::get('promos', [C::class, 'promos']);
    Route::get('files/{key}', [C::class, 'file'])->where('key', '[A-Za-z0-9._-]+');
});
