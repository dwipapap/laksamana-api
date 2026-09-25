<?php

use App\Modules\Akademi\Http\Legacy\AkademiLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'akademi-api-mysql/api.php', AkademiLegacyController::class);
