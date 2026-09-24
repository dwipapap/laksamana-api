<?php

use App\Modules\Jadwal\Http\Legacy\JadwalLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'jadwal-api-mysql/api.php', JadwalLegacyController::class);
