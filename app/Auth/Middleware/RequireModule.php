<?php

namespace App\Auth\Middleware;

use App\Auth\OfficeAccess;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1 gate: `module:<key>` — the Sanctum user must hold the module (same
 * resolution as legacy whoami.modules). `module:<key>,admin` requires being
 * that module's admin (or superadmin). `module:*,admin` = superadmin only.
 * `module:a|b` passes when the user holds any of the listed modules.
 */
class RequireModule
{
    public function __construct(private readonly OfficeAccess $access) {}

    public function handle(Request $request, Closure $next, string $module, ?string $level = null): Response
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('unauthenticated', 'Login required.', 401);
        }
        $id = (string) $user->getKey();
        // `a|b` = any of these modules (one Backend serving several Panels, e.g. stock).
        $keys = explode('|', $module);

        if ($level === 'admin') {
            $ok = $module === '*' ? $this->access->isSuperadmin($id)
                : collect($keys)->contains(fn ($k) => $this->access->isModuleAdmin($id, $k));
            if (! $ok) {
                return ApiResponse::error('forbidden', "Admin rights for module [$module] required.", 403);
            }
        } elseif (! collect($keys)->contains(fn ($k) => $this->access->hasModule($id, $k))) {
            return ApiResponse::error('module_not_granted', "Your account has no access to module [$module].", 403);
        }

        return $next($request);
    }
}
