<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use App\Support\Api\ApiResponse;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyRequest;
use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Refuses writes for one opted-in Modul while its reads remain available. */
final class RejectModuleWrites
{
    public const LEGACY_MESSAGE = 'Server sedang sibuk menyimpan, coba lagi sebentar.';

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! Modules::isInMaintenance($module) || $this->isRead($request, $module)) {
            return $next($request);
        }

        if ($request->is('api/v1/*')) {
            return ApiResponse::error('module_maintenance', self::LEGACY_MESSAGE, 503);
        }

        if (config("laksamana.legacy_policies.{$module}.error_style") === 'status') {
            $response = Envelope::statusError(self::LEGACY_MESSAGE, 503);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        return Envelope::error(self::LEGACY_MESSAGE);
    }

    private function isRead(Request $request, string $module): bool
    {
        if ($request->is('api/v1/*')) {
            return $request->isMethodSafe();
        }

        $policy = config("laksamana.legacy_policies.{$module}", []);
        if (($policy['method_guard'] ?? false) === true) {
            return $request->isMethodSafe();
        }

        $legacy = LegacyRequest::from(
            $request,
            (string) ($policy['default'] ?? ''),
            (bool) ($policy['query_first'] ?? false),
        );

        return in_array($legacy->action, $policy['read'] ?? [], true);
    }
}
