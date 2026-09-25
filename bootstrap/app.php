<?php

use App\Auth\Middleware\RequireModule;
use App\Support\Api\ApiResponse;
use App\Support\Legacy\LegacyCors;
use App\Support\Maintenance\RejectModuleWrites;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Module routes (v1 + legacy) are registered by App\Providers\ModuleServiceProvider.
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'module' => RequireModule::class,
            'maintenance' => RejectModuleWrites::class,
        ]);
        // Old `<modul>-api-mysql/api.php` URLs: open CORS, no session, no CSRF.
        $middleware->group('legacy', [
            LegacyCors::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/v1 error uses the single v1 envelope {error:{code,message,details}}.
        $isV1 = fn (Request $r) => $r->is('api/v1/*') || $r->is('api/v1');

        $exceptions->render(function (ValidationException $e, Request $r) use ($isV1) {
            return $isV1($r) ? ApiResponse::error('validation_failed', $e->getMessage(), 422, $e->errors()) : null;
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($isV1) {
            return $isV1($r) ? ApiResponse::error('unauthenticated', 'Login required.', 401) : null;
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($isV1) {
            if (! $isV1($r)) {
                return null;
            }
            $code = match ($e->getStatusCode()) {
                404 => 'not_found', 403 => 'forbidden', 405 => 'method_not_allowed', 429 => 'too_many_requests',
                default => 'http_error',
            };

            return ApiResponse::error($code, $e->getMessage() ?: $code, $e->getStatusCode());
        });
        $exceptions->render(function (Throwable $e, Request $r) use ($isV1) {
            if (! $isV1($r) || config('app.debug')) {
                return null;
            }

            return ApiResponse::error('server_error', 'Internal server error.', 500);
        });
    })->create();
