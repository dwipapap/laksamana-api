<?php

use App\Modules\Reservasi\Http\Legacy\ReservasiLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'reservasi-api-mysql/api.php', ReservasiLegacyController::class);
