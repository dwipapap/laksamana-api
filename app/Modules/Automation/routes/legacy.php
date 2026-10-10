<?php

use App\Modules\Automation\Http\V1\FeedController;
use Illuminate\Support\Facades\Route;

/*
 * Short public denah URL: /d/<token>.
 *
 * Purpose: a WhatsApp message-template URL button needs a STABLE base
 * (https://api.laksamanamuda.id/d/{{1}}) with a dynamic suffix, so the
 * long /api/v1/automation/denah?t=<token> form lives at this short path
 * too. Public by design — the signed token is the only credential and it
 * expires by itself. See docs/api/automation.md.
 */
Route::get('d/{token}', [FeedController::class, 'denah']);
