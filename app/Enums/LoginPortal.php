<?php

namespace App\Enums;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The login entry points. Every portal posts to Fortify's login pipeline, so the
 * portal decides which users may authenticate there.
 */
enum LoginPortal
{
    case SuperAdmin;
    case Tenant;
    case Customer;

    /**
     * Resolve the portal a login request was made through.
     */
    public static function fromRequest(Request $request): self
    {
        if (tenancy()->initialized) {
            return self::Tenant;
        }

        if ($request->routeIs('admin.login.store', 'admin.passkey.login')) {
            return self::SuperAdmin;
        }

        return self::Customer;
    }

    /**
     * Determine if the user may log in through this portal.
     */
    public function allows(User $user): bool
    {
        return match ($this) {
            self::SuperAdmin => $user->role === Role::SuperAdmin,
            self::Customer => $user->role === Role::Customer,
            // A pending agency is not live: nobody signs in until it is activated.
            self::Tenant => $user->role->isTenantRole()
                && tenancy()->initialized
                && $user->tenant_id === tenant('id')
                && tenant() instanceof Tenant
                && tenant()->status->allowsStaffSignIn(),
        };
    }
}
