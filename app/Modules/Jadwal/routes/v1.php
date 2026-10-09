<?php

use App\Modules\Jadwal\Http\V1\JadwalController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:jadwal'])->prefix('jadwal')->group(function () {
    Route::get('cells', [C::class, 'cells']);
    Route::put('cells', [C::class, 'saveCells']);
    Route::delete('cells', [C::class, 'clearAll'])->middleware('module:jadwal,admin');
    Route::get('shifts', [C::class, 'shifts']);
    Route::get('dw-schedule', [C::class, 'dwSchedule']);
    Route::get('roster', [C::class, 'roster']);
    Route::get('requests', [C::class, 'requests']);
    Route::post('requests', [C::class, 'createRequest']);
    Route::post('requests/{id}/decision', [C::class, 'decide']);
    Route::delete('requests/{id}', [C::class, 'deleteRequest']);
    Route::get('settings', [C::class, 'settings']);
    Route::get('heads', [C::class, 'heads']);
    Route::put('settings', [C::class, 'saveSettings'])->middleware('module:jadwal,admin');
});
