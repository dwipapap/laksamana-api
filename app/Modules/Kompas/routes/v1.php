<?php

use App\Modules\Kompas\Http\V1\KompasController as C;
use Illuminate\Support\Facades\Route;

// Module checks are per endpoint (several modules read kompas): see KompasController.
Route::middleware('auth:sanctum')->prefix('kompas')->group(function () {
    Route::get('state', [C::class, 'state']);
    Route::put('state', [C::class, 'putState']);
    Route::put('targets', [C::class, 'putTargets']);
    Route::put('rekap', [C::class, 'putRekap']);
    Route::get('daily', [C::class, 'daily']);
    Route::get('omset-pic', [C::class, 'omsetPic']);
    Route::get('performa/{divisi}', [C::class, 'performa'])->whereIn('divisi', ['marketing', 'event']);
});
