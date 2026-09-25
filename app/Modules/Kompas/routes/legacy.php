<?php

use App\Modules\Kompas\Http\Legacy\KompasLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'kompas-api-mysql/api.php', KompasLegacyController::class);
