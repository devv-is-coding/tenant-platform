<?php

namespace App\Http\Responses;

use App\Concerns\LandsSuperAdminsOnTheirDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * The passkey package's sign-in response, landing a super admin on the admin
 * dashboard. The browser script follows the JSON `redirect` itself.
 */
class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    use LandsSuperAdminsOnTheirDashboard;

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $redirect = redirect()->intended($this->landingFor($request, (string) config('passkeys.redirect', '/')));

        return $request->wantsJson()
            ? new JsonResponse(['redirect' => $redirect->getTargetUrl()], 200)
            : $redirect;
    }
}
