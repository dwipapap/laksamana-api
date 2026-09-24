<?php

use App\Modules\Account\Http\V1\AccountAdminController;
use App\Modules\Account\Http\V1\AuthController;
use Illuminate\Support\Facades\Route;

// ---- auth -------------------------------------------------------------
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:30,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me/pin', [AuthController::class, 'changePin']);
    Route::put('me/username', [AuthController::class, 'setUsername']);

    // rosters: any authenticated account (legacy exposes these without any gate)
    Route::get('account/roster', [AccountAdminController::class, 'divisiRoster']);
    Route::get('account/modules/{module}/members', [AccountAdminController::class, 'moduleRoster']);

    // roster managers = admins of `jadwal` (Tim HRD included)
    Route::middleware('module:jadwal,admin')->group(function () {
        Route::post('account/roster', [AccountAdminController::class, 'rosterSave']);
        Route::patch('account/roster/{id}', [AccountAdminController::class, 'rosterSave']);
    });

    // superadmin
    Route::middleware('module:*,admin')->prefix('account')->group(function () {
        Route::get('users', [AccountAdminController::class, 'users']);
        Route::post('users', [AccountAdminController::class, 'saveUser']);
        Route::patch('users/{id}', [AccountAdminController::class, 'saveUser']);
        Route::put('users/{id}/active', [AccountAdminController::class, 'setActive']);
        Route::delete('users/{id}', [AccountAdminController::class, 'destroy']);
        Route::put('users/{id}/access', [AccountAdminController::class, 'setModuleAccess']);
        Route::put('users/{id}/admin', [AccountAdminController::class, 'setAdmin']);
        Route::get('modules', [AccountAdminController::class, 'modules']);
    });
});
