<?php

use App\Modules\Reservasi\Http\V1\ReservasiController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:reservasi'])->prefix('reservasi')->group(function () {
    Route::get('reservations', [C::class, 'index']);
    Route::post('reservations', [C::class, 'store']);
    Route::get('reservations/{id}', [C::class, 'show']);
    Route::put('reservations/{id}', [C::class, 'update']);
    Route::patch('reservations/{id}', [C::class, 'patch']);
    Route::delete('reservations/{id}', [C::class, 'destroy']);

    Route::get('master', [C::class, 'master']);
    Route::put('master', [C::class, 'putMaster']);
    Route::get('master/{section}', [C::class, 'section']);
    Route::put('master/{section}', [C::class, 'putSection']);
    Route::put('master/{section}/{id}', [C::class, 'putItem']);
    Route::delete('master/{section}/{id}', [C::class, 'deleteItem']);
    Route::get('audit', [C::class, 'audit']);
    Route::post('audit', [C::class, 'storeAudit']);
    Route::get('files/{key}', [C::class, 'file'])->where('key', '.+');
    Route::put('files/{key}', [C::class, 'putFile'])->where('key', '.+');
});
