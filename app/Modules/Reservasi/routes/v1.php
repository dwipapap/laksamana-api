<?php

use App\Modules\Reservasi\Http\V1\ReservasiController as C;
use Illuminate\Support\Facades\Route;

// Reservasi and Service Excellent are two Panels of the same Backend and the same
// master blob, so either Modul opens this contract; page-level Akses Halaman stays
// in master (perms / sePerms), exactly as the old client-side matrix did.
Route::middleware(['auth:sanctum', 'module:reservasi|service_excellent'])->prefix('reservasi')->group(function () {
    Route::post('reservations', [C::class, 'store']);
    Route::put('reservations/{id}', [C::class, 'update']);
    Route::delete('reservations/{id}', [C::class, 'destroy']);

    Route::get('master', [C::class, 'master']);
    Route::put('master', [C::class, 'putMaster']);
    Route::put('master/{section}', [C::class, 'putSection']);
    Route::put('master/{section}/{id}', [C::class, 'putItem']);
    Route::delete('master/{section}/{id}', [C::class, 'deleteItem']);
    Route::get('audit', [C::class, 'audit']);
    Route::put('files/{key}', [C::class, 'putFile'])->where('key', '.+');
});

// Dana Masuk (G-14): the old Office opened this one page to `cashier` and
// `finance` too (deploy/reservasi/?embed=finance), with verify/reject rights.
// These are the endpoints that page uses. For a holder of cashier/finance
// WITHOUT reservasi|service_excellent the controller narrows them further
// (see DanaMasukGate): PATCH writes DP/transfer fields only, master reads
// only `dpMethods`. Everyone else sees no difference.
Route::middleware(['auth:sanctum', 'module:reservasi|service_excellent|cashier|finance'])->prefix('reservasi')->group(function () {
    Route::get('reservations', [C::class, 'index']);
    Route::get('reservations/{id}', [C::class, 'show']);
    Route::patch('reservations/{id}', [C::class, 'patch']);
    Route::get('master/{section}', [C::class, 'section']);
    Route::post('audit', [C::class, 'storeAudit']);
    Route::get('files/{key}', [C::class, 'file'])->where('key', '.+');
});
