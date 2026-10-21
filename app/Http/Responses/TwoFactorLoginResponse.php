<?php

namespace App\Http\Responses;

use App\Concerns\LandsSuperAdminsOnTheirDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's response once a two-factor challenge is passed, landing a super
 * admin on the admin dashboard.
 */
class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
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
            ? new JsonResponse('', 204)
            : redirect()->intended($this->landingFor($request, Fortify::redirects('login')));
    }
}
