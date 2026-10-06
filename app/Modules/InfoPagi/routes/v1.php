<?php

use App\Modules\InfoPagi\Http\V1\InfoPagiController as Info;
use Illuminate\Support\Facades\Route;

// Morning briefing for the 07:00 WIB automation. Gates on reservasi like
// the recap endpoint (same n8n token), NOT on event|marketing — granting
// those two modules to the token account would also open their write
// endpoints, while this briefing is read-only by construction.
Route::middleware(['auth:sanctum', 'module:reservasi'])->prefix('info')->group(function () {
    Route::get('pagi', [Info::class, 'show']);
});
