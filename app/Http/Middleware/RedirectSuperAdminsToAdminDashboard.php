<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends super admins from the customer dashboard to the platform console.
 *
 * Every login path - password, two-factor and passkey - lands on the dashboard,
 * so redirecting here covers all of them in one place.
 */
class RedirectSuperAdminsToConsole
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
