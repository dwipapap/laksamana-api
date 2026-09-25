<?php

use App\Modules\Hr\Http\V1\HrController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:hr'])->prefix('hr')->group(function () {
    Route::get('state', [C::class, 'state']);
    Route::get('stats', [C::class, 'stats']);

    // Akademi training completion per user (in-process read; the old page fetched akademi's trainingStats)
    Route::get('training-stats', [C::class, 'trainingStats']);

    // nested maps, addressed per cell / per month
    Route::get('kpi-actuals', [C::class, 'kpiActuals']);
    Route::put('kpi-actuals/{divId}/{month}/{itemId}', [C::class, 'putKpiActual']);
    Route::get('monthly-inputs', [C::class, 'monthlyInputs']);
    Route::put('monthly-inputs/{empId}/{month}', [C::class, 'putMonthly']);
    Route::get('attendance', [C::class, 'attendance']);
    Route::put('attendance/{month}', [C::class, 'putAttendance'])->where('month', '\d{4}-\d{2}');
    Route::get('settings', [C::class, 'settings']);

    // audit trail: every HR user appends (login, saves…); reading it is the admin "Audit Log" page
    Route::post('audit', [C::class, 'appendAudit']);

    $all = 'divisions|employees|kpi-templates|okrs|reviews|competencies|trainings|training-records|coachings|rewards|badges|violations|feedbacks|career-paths|successions|moods|suggestions|calendar';
    Route::get('{resource}', [C::class, 'index'])->where('resource', $all);
    Route::get('{resource}/{id}', [C::class, 'show'])->where('resource', $all);

    // Pages behind the frontend's `manageOps` perm (= Office hr module admin):
    // Kru, Kalender HR, Pengaturan, Audit Log. Enforced here, not only hidden.
    Route::middleware('module:hr,admin')->group(function () {
        Route::put('settings/{key}', [C::class, 'putSetting']);
        Route::get('audit', [C::class, 'audit']);
    });

    // writes to `employees` and `calendar` (Kru, Kalender HR) are admin-only too: checked in the
    // controller, because one URI pattern can carry only one route per method
    Route::post('{resource}', [C::class, 'store'])->where('resource', $all);
    Route::put('{resource}/{id}', [C::class, 'update'])->where('resource', $all);
    Route::patch('{resource}/{id}', [C::class, 'patch'])->where('resource', $all);
    Route::delete('{resource}/{id}', [C::class, 'destroy'])->where('resource', $all);
});
