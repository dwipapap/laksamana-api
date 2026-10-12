<?php

use App\Modules\Automation\Http\V1\FeedController;
use Illuminate\Support\Facades\Route;

/*
 * Short public denah URL: /d/<token>.
 *
 * Purpose: guests open the temporary map at a short, stable path. The token
 * is the only credential. The public base the bot hands out is configurable
 * (config/denah.php → DENAH_PUBLIC_BASE, e.g. https://laksamanamuda.id) and
 * may live on another host that forwards here; this route keeps the API's own
 * /d/<token> working too. See docs/api/automation.md.
 */
Route::get('d/{token}', [FeedController::class, 'denah']);
