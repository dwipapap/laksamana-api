<?php

use App\Modules\Dw\Http\V1\DwController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:dw'])->prefix('dw')->group(function () {
    Route::get('overview', [C::class, 'overview']);
    Route::get('schedule', [C::class, 'schedule']);
    Route::get('guests', [C::class, 'guests']);
    Route::get('workers', [C::class, 'workers']);
    Route::post('workers', [C::class, 'createWorker']);
    Route::get('workers/{id}', [C::class, 'worker']);
    Route::put('workers/{id}', [C::class, 'saveWorker']);
    Route::delete('workers/{id}', [C::class, 'deleteWorker']);
    Route::get('requests', [C::class, 'requests']);
    Route::post('requests', [C::class, 'createRequest']);
    Route::get('requests/{id}', [C::class, 'request']);
    Route::put('requests/{id}', [C::class, 'saveRequest']);
    Route::post('requests/{id}/decision', [C::class, 'decideRequest']);
    Route::post('requests/{id}/assign', [C::class, 'assign']);
    Route::delete('requests/{id}', [C::class, 'deleteRequest']);
    Route::get('assignments', [C::class, 'assignments']);
    Route::post('assignments', [C::class, 'createAssignment']);
    Route::post('assignments/decisions', [C::class, 'decideMany']);
    Route::get('assignments/{id}', [C::class, 'assignment']);
    Route::post('assignments/{id}/decision', [C::class, 'decideAssignment']);
    Route::post('assignments/{id}/attendance', [C::class, 'attendance']);
    Route::post('assignments/{id}/replace', [C::class, 'replace']);
    Route::delete('assignments/{id}', [C::class, 'deleteAssignment']);
    Route::get('settings', [C::class, 'settings']);
    Route::put('settings', [C::class, 'saveSettings']);
    Route::post('payments/marks', [C::class, 'markPaid']);
    Route::post('admin/clear', [C::class, 'clearAll'])->middleware('module:dw,admin');
});
