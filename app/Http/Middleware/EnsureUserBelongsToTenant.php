<?php

namespace App\Http\Middleware;

use App\Enums\LoginPortal;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the current tenant's admins, managers and staff may use its pages.
 *
 * Belonging to the tenant is not enough: its customers belong to it too, and
 * the tenant portal requires a staff role (Role::isTenantRole()) as well.
 *
 * A deactivated account is signed out here, on its next request, so locking
 * out a leaver also ends the sessions they already have open. Persistent for
 * Livewire (TenancyServiceProvider), so it holds on update requests too.
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
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('tenant.homepage');
        }

        abort_unless(LoginPortal::Tenant->allows($request->user()), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
