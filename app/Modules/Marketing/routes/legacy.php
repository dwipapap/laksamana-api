<?php

use App\Modules\Marketing\Http\Legacy\MarketingLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'marketing-api-mysql/api.php', MarketingLegacyController::class);
