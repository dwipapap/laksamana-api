<?php

use App\Modules\Hlife\Http\Legacy\HlifeLegacyController;
use Illuminate\Support\Facades\Route;

// any(): legacy answered every other method with 405 "metode tidak didukung".
Route::any('howandi-life-api-mysql/api.php', HlifeLegacyController::class);
