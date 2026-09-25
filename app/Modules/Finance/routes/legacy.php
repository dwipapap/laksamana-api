<?php

use App\Modules\Finance\Http\Legacy\FinanceLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'finance-api-mysql/api.php', FinanceLegacyController::class);
