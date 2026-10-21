<?php

namespace App\Http\Responses;

use App\Concerns\LandsSuperAdminsOnTheirDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's password sign-in response, landing a super admin on the admin
 * dashboard.
 */
class LoginResponse implements LoginResponseContract
{
    use LandsSuperAdminsOnTheirDashboard;

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false])
            : redirect()->intended($this->landingFor($request, Fortify::redirects('login')));
    }
}
