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
        Route::put('account/roster/{id}/active', [AccountAdminController::class, 'rosterSetActive']);
        Route::delete('account/roster/{id}', [AccountAdminController::class, 'rosterDestroy']);
    });

    // investor account from Finance → Brankas: admins of the modules holding the investor list
    Route::post('account/investor-akun', [AccountAdminController::class, 'investorAccount'])
        ->middleware('module:investor|brankas|finance,admin');

    // superadmin
    Route::middleware('module:*,admin')->prefix('account')->group(function () {
        Route::get('users', [AccountAdminController::class, 'users']);
        Route::post('users', [AccountAdminController::class, 'saveUser']);
        Route::post('users/bulk', [AccountAdminController::class, 'bulkUsers']);
        Route::patch('users/{id}', [AccountAdminController::class, 'saveUser']);
        Route::put('users/{id}/active', [AccountAdminController::class, 'setActive']);
        Route::delete('users/{id}', [AccountAdminController::class, 'destroy']);
        Route::put('users/{id}/access', [AccountAdminController::class, 'setModuleAccess']);
        Route::put('users/{id}/admin', [AccountAdminController::class, 'setAdmin']);
        Route::get('modules', [AccountAdminController::class, 'modules']);
        Route::post('modules', [AccountAdminController::class, 'syncModules']);
        Route::patch('modules/{key}', [AccountAdminController::class, 'saveModule']);
        Route::post('import', [AccountAdminController::class, 'import']);
    });
});
