<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Where a sign-in lands when no page was asked for first.
 *
 * Every sign-in path otherwise lands on the customer `/dashboard`, which a
 * super admin then had to be bounced from. Sending them to their own
 * dashboard here removes the hop for password, two-factor and passkey sign-in
 * alike. A page asked for before signing in still wins, through intended().
 */
trait LandsSuperAdminsOnTheirDashboard
{
    /**
     * Get the default landing page for the user who has just signed in.
     */
    protected function landingFor(Request $request, string $default): string
    {
        $user = $request->user();

        return $user instanceof User && $user->isSuperAdmin()
            ? route('admin.dashboard')
            : $default;
    }
}
