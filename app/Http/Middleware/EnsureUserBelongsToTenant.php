<?php

namespace App\Http\Middleware;

use App\Enums\LoginPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the current tenant's admins, managers and staff may use its pages.
 *
 * Belonging to the tenant is not enough: its customers belong to it too, and
 * the tenant portal requires a staff role (Role::isTenantRole()) as well.
 */
class EnsureUserBelongsToTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(LoginPortal::Tenant->allows($request->user()), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
