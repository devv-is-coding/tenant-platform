<?php

use App\Models\User;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\freezeSecond;

beforeEach(function () {
    skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
});

test('confirming two factor authentication records the confirmation time', function () {
    freezeSecond();
    $secret = app(Google2FA::class)->generateSecretKey();
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
    ])->save();

    $response = actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.confirm'), [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ]);

    $response->assertSessionHasNoErrors();
    expect($user->refresh()->two_factor_confirmed_at)->toEqual(now());
});
