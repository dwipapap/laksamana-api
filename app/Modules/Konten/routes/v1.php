<?php

use App\Modules\Konten\Http\V1\CampaignController as CA;
use App\Modules\Konten\Http\V1\KontenAdminController as AD;
use App\Modules\Konten\Http\V1\KontenController as C;
use Illuminate\Support\Facades\Route;

// Design options for Marketing's Request Design form (G-16): brands and active
// crew only, none of the other Konten state. The legacy form read these straight
// from konten getAll; this narrow read opens to both modules.
Route::middleware(['auth:sanctum', 'module:konten|marketing'])->prefix('konten')->group(function () {
    Route::get('design-options', [C::class, 'designOptions']);
});

Route::middleware(['auth:sanctum', 'module:konten'])->prefix('konten')->group(function () {
    // bootstrap + diagnostics
    Route::get('state', [C::class, 'state']);
    Route::get('stats', [C::class, 'stats']);

    // activity trail
    Route::get('logs', [C::class, 'logs']);
    Route::post('logs', [C::class, 'logActivity']);

    // settings documents (settings, perms, seeded)
    Route::get('documents/{doc}', [C::class, 'document']);
    Route::put('documents/{doc}', [C::class, 'putDocument']);

    // files
    Route::post('files', [C::class, 'upload']);
    Route::get('files/{key}', [C::class, 'file'])->where('key', '[A-Za-z0-9._-]+');

    // per-platform performance figures living inside the content row
    Route::put('content/{id}/performance', [C::class, 'performance']);

    // records: users, brands, campaigns, content, prod-tasks, shootings,
    // assets, bank, kols, visits, ads, ad-funds, notifications
    $res = 'users|brands|campaigns|content|prod-tasks|shootings|assets|bank|kols|visits|ads|ad-funds|notifications';
    Route::get('{resource}', [C::class, 'index'])->where('resource', $res);
    Route::post('{resource}', [C::class, 'store'])->where('resource', $res);
    Route::get('{resource}/{id}', [C::class, 'show'])->where('resource', $res);
    Route::put('{resource}/{id}', [C::class, 'update'])->where('resource', $res);
    Route::patch('{resource}/{id}', [C::class, 'patch'])->where('resource', $res);
    Route::delete('{resource}/{id}', [C::class, 'destroy'])->where('resource', $res);
});

// Campaign analytics panel (Insight, Kalender, Riset, Bank Konten, Pengaturan,
// TikTok/Instagram OAuth) — v1 port of konten-mysql/lib_campaign.php (#276).
// Gate konten|campaign: the Beranda kartu holds both keys (old Planning = konten,
// old Analytics = campaign). Settings writes need a campaign module admin.
Route::middleware(['auth:sanctum', 'module:konten|campaign'])->prefix('konten/analytics')->group(function () {
    Route::get('state', [CA::class, 'state']);
    Route::get('top', [CA::class, 'top']);
    Route::get('accounts', [CA::class, 'accounts']);
    Route::get('bank', [CA::class, 'bank']);
    Route::get('settings', [CA::class, 'settings']);

    Route::post('insights', [CA::class, 'saveInsight']);
    Route::patch('insights/{id}', [CA::class, 'saveInsight']);
    Route::post('insights/{id}/arsip', [CA::class, 'archiveInsight']);
    Route::post('insights/suggest', [CA::class, 'suggestInsights']);

    Route::post('schedules', [CA::class, 'saveSchedule']);
    Route::patch('schedules/{id}', [CA::class, 'saveSchedule']);

    Route::post('research', [CA::class, 'runResearch']);

    Route::post('bank', [CA::class, 'saveBank']);
    Route::patch('bank/{id}', [CA::class, 'saveBank']);
    Route::post('bank/{id}/arsip', [CA::class, 'archiveBank']);
    Route::delete('bank/{id}', [CA::class, 'deleteBank']);
    Route::post('bank/generate', [CA::class, 'generateBank']);

    Route::post('oauth/tiktok/start', [CA::class, 'tiktokStart']);
    Route::post('oauth/tiktok/sync', [CA::class, 'tiktokSync']);
    Route::post('oauth/tiktok/disconnect', [CA::class, 'tiktokDisconnect']);
    Route::post('oauth/instagram/start', [CA::class, 'igStart']);
    Route::post('oauth/instagram/sync', [CA::class, 'igSync']);
    Route::post('oauth/instagram/disconnect', [CA::class, 'igDisconnect']);
});

Route::middleware(['auth:sanctum', 'module:campaign,admin'])->prefix('konten/analytics')->group(function () {
    Route::put('settings', [CA::class, 'saveSettings']);
});

// OAuth callbacks are public: the single-use state token is the guard,
// like the legacy campaign-tiktok.php / campaign-instagram.php.
Route::prefix('konten/analytics')->group(function () {
    Route::get('oauth/tiktok/callback', [CA::class, 'tiktokCallback']);
    Route::get('oauth/instagram/callback', [CA::class, 'igCallback']);
});

// G-13 (#187): restore the whole database from a backup file — module admin, typed confirmation PULIHKAN.
Route::middleware(['auth:sanctum', 'module:konten,admin'])->post('konten/admin/restore', [AD::class, 'restore']);
