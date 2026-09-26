<?php

use App\Modules\Ticketing\Http\Legacy\TicketingLegacyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'OPTIONS'], 'ticketing-api/api.php', TicketingLegacyController::class);
