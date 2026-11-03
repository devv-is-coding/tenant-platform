<?php

use Illuminate\Testing\TestResponse;

use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withServerVariables;

/**
 * A failed sign-in by the client, arriving the way it would behind a load
 * balancer: from the balancer's address, naming the client in X-Forwarded-For.
 */
function failedLoginThroughBalancer(string $clientIp): TestResponse
{
    withServerVariables(['REMOTE_ADDR' => '10.0.0.1']);

    return withHeaders(['X-Forwarded-For' => $clientIp])
        ->post(route('login.store'), ['email' => 'maria@example.com', 'password' => 'wrong-password']);
}

test('behind a trusted load balancer, each client has its own login throttle', function () {
    config(['app.trusted_proxies' => '10.0.0.1']);
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        failedLoginThroughBalancer('203.0.113.5');
    }

    $otherClient = failedLoginThroughBalancer('203.0.113.6');

    $otherClient->assertSessionHasErrors('email');
    failedLoginThroughBalancer('203.0.113.5')->assertTooManyRequests();
});

test('with no proxy trusted, a forwarded IP is ignored, so it cannot dodge the throttle', function () {
    config(['app.trusted_proxies' => null]);
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        failedLoginThroughBalancer('203.0.113.5');
    }

    $claimingAnotherIp = failedLoginThroughBalancer('203.0.113.6');

    $claimingAnotherIp->assertTooManyRequests();
});

test('behind a trusted load balancer that ended https, the app\'s links stay on https', function () {
    config(['app.trusted_proxies' => '*']);

    $response = withHeaders(['X-Forwarded-Proto' => 'https'])->get(route('admin.dashboard'));

    expect($response->headers->get('Location'))->toStartWith('https://');
});
