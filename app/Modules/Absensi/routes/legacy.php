<?php

use App\Modules\Absensi\Http\Legacy\AbsensiLegacyController;
use Illuminate\Support\Facades\Route;

// The old backend lives at /absensi/api/api.php, on its own subdomain where
// it resolves to /api/api.php — both paths serve the same controller.
Route::match(['GET', 'POST', 'OPTIONS'], 'absensi/api/api.php', AbsensiLegacyController::class);
Route::match(['GET', 'POST', 'OPTIONS'], 'api/api.php', AbsensiLegacyController::class);
