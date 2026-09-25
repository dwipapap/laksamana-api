<?php

use App\Modules\Bd\Http\Legacy\BdLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'bd-api-mysql/api.php', BdLegacyController::class);
