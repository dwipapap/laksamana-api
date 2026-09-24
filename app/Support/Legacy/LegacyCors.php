<?php

namespace App\Support\Legacy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every legacy api.php sent:
 *   Access-Control-Allow-Origin: *
 *   Access-Control-Allow-Methods: GET, POST, OPTIONS
 *   Access-Control-Allow-Headers: Content-Type
 * and answered OPTIONS with 204. Reproduced for the `legacy` route group.
 */
class LegacyCors
{
    private const HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, x-callback-token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return new \Illuminate\Http\Response('', 204, self::HEADERS);
        }
        $response = $next($request);
        foreach (self::HEADERS as $k => $v) {
            $response->headers->set($k, $v);
        }

        return $response;
    }
}
