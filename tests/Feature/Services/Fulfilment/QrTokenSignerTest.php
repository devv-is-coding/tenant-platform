<?php

use App\Models\Booking;
use App\Models\ItineraryStop;
use App\Services\Fulfilment\QrTokenSigner;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

/**
 * Read the payload half of a code, as anyone holding the code could.
 *
 * @return array<string, mixed>
 */
function qrPayload(string $token): array
{
    return json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);
}

/**
 * Rebuild a code's payload half from claims, keeping its signature.
 *
 * @param  array<string, mixed>  $claims
 */
function withQrPayload(string $token, array $claims): string
{
    return rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=').'.'.explode('.', $token)[1];
}

test('a booking code names its booking and carries nothing about the guest', function () {
    travelTo('2026-12-01 14:12:00');
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create());

    $token = withinTenant($tenant, fn (): string => app(QrTokenSigner::class)->forBooking($booking));

    expect(withinTenant($tenant, fn (): int => app(QrTokenSigner::class)->bookingIdFrom($token)))->toBe($booking->id)
        ->and(qrPayload($token))->toBe([
            'k' => 'booking',
            't' => $tenant->id,
            'id' => $booking->id,
            'exp' => Carbon::parse('2026-12-01 14:22:00')->getTimestamp(),
        ]);
});

test('a code that was tampered with is refused', function (Closure $tamper) {
    $tenant = tenantOnDomain();
    $token = withinTenant($tenant, fn (): string => app(QrTokenSigner::class)->forBooking(Booking::factory()->create()));

    expect(fn (): int => withinTenant($tenant, fn (): int => app(QrTokenSigner::class)->bookingIdFrom($tamper($token))))
        ->toThrow(ValidationException::class, 'This QR code is not valid here.');
})->with([
    'another booking named in the payload' => [fn (string $token): string => withQrPayload($token, [...qrPayload($token), 'id' => qrPayload($token)['id'] + 1])],
    'the expiry pushed back' => [fn (string $token): string => withQrPayload($token, [...qrPayload($token), 'exp' => qrPayload($token)['exp'] + 86400])],
    'a forged signature' => [fn (string $token): string => explode('.', $token)[0].'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')],
    'the signature cut off' => [fn (string $token): string => explode('.', $token)[0]],
    'not a code at all' => [fn (string $token): string => 'DGTE-2026-0001'],
]);

test('a tour stop\'s code is refused where a booking\'s is expected', function () {
    $tenant = tenantOnDomain();
    $token = withinTenant($tenant, fn (): string => app(QrTokenSigner::class)->forStop(ItineraryStop::factory()->create()));

    expect(fn (): int => withinTenant($tenant, fn (): int => app(QrTokenSigner::class)->bookingIdFrom($token)))
        ->toThrow(ValidationException::class, 'This QR code is not valid here.');
});

test('a tour stop\'s code works for 30 seconds from signing, and is refused one window later', function () {
    travelTo('2026-12-02 09:40:00');
    $tenant = tenantOnDomain();
    [$stop, $token] = withinTenant($tenant, function (): array {
        $stop = ItineraryStop::factory()->create();

        return [$stop, app(QrTokenSigner::class)->forStop($stop)];
    });

    travel(29)->seconds();
    $readInTime = withinTenant($tenant, fn (): int => app(QrTokenSigner::class)->stopIdFrom($token));
    travel(1)->seconds();

    expect($readInTime)->toBe($stop->id)
        ->and(fn (): int => withinTenant($tenant, fn (): int => app(QrTokenSigner::class)->stopIdFrom($token)))
        ->toThrow(ValidationException::class, 'This QR code has expired. Scan a fresh one.');
});

test('a booking\'s code works for ten minutes from signing', function () {
    travelTo('2026-12-01 14:12:00');
    $tenant = tenantOnDomain();
    [$booking, $token] = withinTenant($tenant, function (): array {
        $booking = Booking::factory()->create();

        return [$booking, app(QrTokenSigner::class)->forBooking($booking)];
    });

    travel(599)->seconds();
    $readInTime = withinTenant($tenant, fn (): int => app(QrTokenSigner::class)->bookingIdFrom($token));
    travel(1)->seconds();

    expect($readInTime)->toBe($booking->id)
        ->and(fn (): int => withinTenant($tenant, fn (): int => app(QrTokenSigner::class)->bookingIdFrom($token)))
        ->toThrow(ValidationException::class, 'This QR code has expired. Scan a fresh one.');
});
