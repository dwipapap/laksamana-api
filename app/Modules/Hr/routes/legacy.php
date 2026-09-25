<?php

use App\Modules\Hr\Http\Legacy\HrLegacyController;
use Illuminate\Support\Facades\Route;

// any(): legacy answered every other method with 405 "metode tidak didukung".
Route::any('hr-api-mysql/api.php', HrLegacyController::class);
