<?php

use App\Modules\Konten\Http\Legacy\KontenLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'konten-api-mysql/api.php', KontenLegacyController::class);
