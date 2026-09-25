<?php

use App\Modules\Dw\Http\Legacy\DwLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'dw-api-mysql/api.php', DwLegacyController::class);
