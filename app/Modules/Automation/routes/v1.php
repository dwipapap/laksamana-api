<?php

use App\Modules\Automation\Http\V1\FeedController;
use Illuminate\Support\Facades\Route;

/*
 * Read-only n8n feeds (#234). The gate is the token ability
 * `automation:read` (middleware `automation.token`), NOT a Modul: the
 * workflow token must not open any Office module, and Sanctum's own
 * `abilities:` middleware would let the staff `*` tokens through.
 *
 * Rate limited to 30 requests/minute; the schedule (07:00 / 17:00 WIB) needs
 * far less, the limit only stops a runaway loop.
 */
Route::middleware(['auth:sanctum', 'automation.token', 'throttle:30,1'])->prefix('automation')->group(function () {
    Route::get('reservasi-harian', [FeedController::class, 'reservasiHarian']);
    Route::get('info-pagi', [FeedController::class, 'infoPagi']);
    Route::get('event-publik', [FeedController::class, 'eventPublik']);
    Route::get('talent-hari-ini', [FeedController::class, 'talentHariIni']);
    Route::post('denah-link', [FeedController::class, 'denahLink']);
});

/*
 * Public denah page (option A): no login — the signed ?t= token is the only
 * credential. Same throttle as the feeds; nothing is stored server-side.
 */
Route::middleware(['throttle:30,1'])->prefix('automation')->group(function () {
    Route::get('denah', [FeedController::class, 'denah']);
});
