<?php

use App\Modules\Event\Http\V1\EventController as C;
use Illuminate\Support\Facades\Route;

// The Event Planner's page permissions (Akses Halaman per peran) are enforced by
// the app itself; the old backend was open to every holder, so v1 gates on the
// module only.
Route::middleware(['auth:sanctum', 'module:event'])->prefix('event')->group(function () {
    Route::get('state', [C::class, 'state']);
    Route::get('stats', [C::class, 'stats']);
    Route::get('events-on/{date}', [C::class, 'eventsOn']);

    // settings documents: entertainmentRules, role, layoutTemplates
    $keys = 'entertainmentRules|role|layoutTemplates';
    Route::get('settings', [C::class, 'settings']);
    Route::get('settings/{key}', [C::class, 'setting'])->where('key', $keys);
    Route::put('settings/{key}', [C::class, 'putSetting'])->where('key', $keys);

    // event details (rundown, budget, sponsors…): one document per event
    Route::get('event-details', [C::class, 'details']);
    Route::get('event-details/{eventId}', [C::class, 'detail']);
    Route::put('event-details/{eventId}', [C::class, 'putDetail']);
    Route::delete('event-details/{eventId}', [C::class, 'deleteDetail']);

    // check-ins: append-only (no update, no delete)
    Route::get('checkins', [C::class, 'checkins']);
    Route::post('checkins', [C::class, 'addCheckin']);

    // files (talent documents incl. KTP, transfer receipts, posters)
    Route::post('files', [C::class, 'upload']);
    Route::get('files/{key}', [C::class, 'file'])->where('key', '[A-Za-z0-9._-]+');

    $res = 'talents|events|schedules|recurring-rules|talent-payments|ticket-classes|seats|orders|tickets|ideas|refunds|calendar-extra';
    Route::get('{resource}', [C::class, 'index'])->where('resource', $res);
    Route::post('{resource}', [C::class, 'store'])->where('resource', $res);
    Route::get('{resource}/{id}', [C::class, 'show'])->where('resource', $res);
    Route::put('{resource}/{id}', [C::class, 'update'])->where('resource', $res);
    Route::patch('{resource}/{id}', [C::class, 'patch'])->where('resource', $res);
    Route::delete('{resource}/{id}', [C::class, 'destroy'])->where('resource', $res);
});
