<?php

namespace App\Http\Middleware;

use App\Enums\LoginPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends one of the agency's signed-in staff from its homepage, which exists
 * to lead to the login, on to its dashboard. The agency's side of
 * RedirectSuperAdminsToAdminDashboard.
 */
class RedirectAgencyStaffToDashboard
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && LoginPortal::Tenant->allows($user)) {
            return redirect()->route('tenant.dashboard');
        }

        return $next($request);
    }
}
