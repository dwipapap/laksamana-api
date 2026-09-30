<?php

use App\Modules\Marketing\Http\V1\MarketingController as C;
use Illuminate\Support\Facades\Route;

// DP Event (G-15): the Dana Masuk page (Reservasi, and Cashier / Kas Kecil
// through it) shows event & VIP payments next to reservation DPs. The legacy
// endpoint (dpMasuk) had no module gate; this one opens to those Modules only.
// It is deliberately narrow — payments only, no CRM, pipeline or invoice.
Route::middleware(['auth:sanctum', 'module:marketing|reservasi|cashier|finance'])->prefix('marketing')->group(function () {
    Route::get('dp', [C::class, 'dp']);
});

Route::middleware(['auth:sanctum', 'module:marketing'])->prefix('marketing')->group(function () {
    // bootstrap + read models
    Route::get('state', [C::class, 'state']);
    Route::get('events-on/{date}', [C::class, 'eventsOn'])->where('date', '\d{4}-\d{2}-\d{2}');
    Route::get('design-queue', [C::class, 'designQueue']);
    Route::put('design-requests/{id}/progress', [C::class, 'designProgress']);
    Route::put('design-options', [C::class, 'designOptions']);

    // timeline
    Route::get('activities', [C::class, 'activities']);
    Route::post('activities', [C::class, 'logActivity']);

    // settings documents (settings, baseline, rolePerms, roleNav, menuDb, katalog, fbFormats, fbSubs, roleAcc)
    Route::get('documents/{doc}', [C::class, 'document']);
    Route::put('documents/{doc}', [C::class, 'putDocument']);

    // files
    Route::post('files', [C::class, 'upload']);
    Route::post('files/chunks', [C::class, 'uploadChunk']);
    Route::get('files/{key}', [C::class, 'file'])->where('key', '[A-Za-z0-9._-]+');

    // records: clients, events, followups, approvals, users, staff, task-templates,
    // task-categories, categories, notifications, vip, design-requests
    $res = 'clients|events|followups|approvals|users|staff|task-templates|task-categories|categories|notifications|vip|design-requests';
    Route::get('{resource}', [C::class, 'index'])->where('resource', $res);
    Route::post('{resource}', [C::class, 'store'])->where('resource', $res);
    Route::get('{resource}/{id}', [C::class, 'show'])->where('resource', $res);
    Route::put('{resource}/{id}', [C::class, 'update'])->where('resource', $res);
    Route::patch('{resource}/{id}', [C::class, 'patch'])->where('resource', $res);
    Route::delete('{resource}/{id}', [C::class, 'destroy'])->where('resource', $res);
});
