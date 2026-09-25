<?php

use App\Modules\Hr\Http\V1\HrController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:hr'])->prefix('hr')->group(function () {
    Route::get('state', [C::class, 'state']);
    Route::get('stats', [C::class, 'stats']);

    // nested maps, addressed per cell / per month
    Route::get('kpi-actuals', [C::class, 'kpiActuals']);
    Route::put('kpi-actuals/{divId}/{month}/{itemId}', [C::class, 'putKpiActual']);
    Route::get('monthly-inputs', [C::class, 'monthlyInputs']);
    Route::put('monthly-inputs/{empId}/{month}', [C::class, 'putMonthly']);
    Route::get('attendance', [C::class, 'attendance']);
    Route::put('attendance/{month}', [C::class, 'putAttendance'])->where('month', '\d{4}-\d{2}');
    Route::get('settings', [C::class, 'settings']);
    Route::put('settings/{key}', [C::class, 'putSetting']);

    // append-only audit trail
    Route::get('audit', [C::class, 'audit']);
    Route::post('audit', [C::class, 'appendAudit']);

    $res = 'divisions|employees|kpi-templates|okrs|reviews|competencies|trainings|training-records|coachings|rewards|badges|violations|feedbacks|career-paths|successions|moods|suggestions|calendar';
    Route::get('{resource}', [C::class, 'index'])->where('resource', $res);
    Route::post('{resource}', [C::class, 'store'])->where('resource', $res);
    Route::get('{resource}/{id}', [C::class, 'show'])->where('resource', $res);
    Route::put('{resource}/{id}', [C::class, 'update'])->where('resource', $res);
    Route::patch('{resource}/{id}', [C::class, 'patch'])->where('resource', $res);
    Route::delete('{resource}/{id}', [C::class, 'destroy'])->where('resource', $res);
});
