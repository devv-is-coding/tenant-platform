<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a signed-in super admin to the admin dashboard.
 *
 * Guards the pages a super admin has no use for once signed in: the customer
 * dashboard, which an old bookmark may still open, and the admin homepage,
 * which exists to lead to the login.
 */
class RedirectSuperAdminsToAdminDashboard
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuperAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        return $next($request);
    }
}
