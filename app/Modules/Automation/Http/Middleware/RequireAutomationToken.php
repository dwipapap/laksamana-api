<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Middleware;

use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * `automation.token` — the automation routes accept ONLY a token that lists
 * the exact ability `automation:read` (#234).
 *
 * Sanctum's own `abilities:automation:read` middleware would also let a `*`
 * token through, and every staff login token is created with `*`, so the
 * check is deliberate here. A session-authenticated request (no token) is
 * refused too.
 */
final class RequireAutomationToken
{
    public const ABILITY = 'automation:read';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $abilities = $token instanceof PersonalAccessToken ? (array) $token->abilities : [];

        if (! in_array(self::ABILITY, $abilities, true)) {
            return ApiResponse::error('forbidden', 'A token with the ['.self::ABILITY.'] ability is required.', 403);
        }

        return $next($request);
    }
}
