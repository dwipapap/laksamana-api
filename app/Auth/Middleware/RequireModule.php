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

        if ($level === 'admin') {
            $ok = $module === '*' ? $this->access->isSuperadmin($id) : $this->access->isModuleAdmin($id, $module);
            if (! $ok) {
                return ApiResponse::error('forbidden', "Admin rights for module [$module] required.", 403);
            }
        } elseif (! $this->access->hasModule($id, $module)) {
            return ApiResponse::error('module_not_granted', "Your account has no access to module [$module].", 403);
        }

        return $next($request);
    }
}
