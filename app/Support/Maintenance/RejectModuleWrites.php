<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use App\Support\Api\ApiResponse;
use App\Support\Legacy\Envelope;
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
        if ($request->isMethodSafe() || ! Modules::isInMaintenance($module)) {
            return $next($request);
        }

        if ($request->is('api/v1/*')) {
            return ApiResponse::error('module_maintenance', self::LEGACY_MESSAGE, 503);
        }

        return Envelope::error(self::LEGACY_MESSAGE);
    }
}
