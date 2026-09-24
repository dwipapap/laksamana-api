<?php

use App\Modules\Account\Http\Legacy\AccountLegacyController;
use Illuminate\Support\Facades\Route;

// Old URL, unchanged: frontends call ../account-api-mysql/api.php
Route::match(['GET', 'POST', 'OPTIONS'], 'account-api-mysql/api.php', AccountLegacyController::class);
