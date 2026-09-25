<?php

use App\Modules\Event\Http\Legacy\EventLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'event-api-mysql/api.php', EventLegacyController::class);
