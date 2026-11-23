<?php

namespace App\Services\Fulfilment;

use App\Models\Booking;
use App\Models\ItineraryStop;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Signs and reads the QR codes check-in runs on, in both directions (P21):
 * the guest's booking code that staff scan at a hotel, and a tour stop's code
 * on the guide's device that the guest scans.
 *
 * A code is `payload.signature`, both base64url. The payload holds only what
 * kind of code it is, the agency, the record's id and an expiry: never a
 * name, email or amount, since anyone holding the code can read it. Only the
 * server can check it.
 *
 * **Each agency signs with its own key**, derived from the app key, so one
 * agency's codes never verify at another, and the payload's agency is checked
 * too. **The kind is signed in**, so a stop's code cannot stand in for a
 * booking's. **A stop's code lives 30 seconds**: geofencing is out of scope,
 * so the code is the only proof the guest is at the stop, and a screenshot of
 * it must be useless almost at once. The guide's device re-signs it before
 * it runs out.
 */
class QrTokenSigner
{
    /**
     * How long a booking's code is good for. The app fetches a fresh one each
     * time it shows it.
     */
    public const BOOKING_TTL_SECONDS = 600;

    /**
     * How long a tour stop's code is good for, from the moment it is signed.
     */
    public const STOP_TTL_SECONDS = 30;

    private const KIND_BOOKING = 'booking';

    private const KIND_STOP = 'stop';

    /**
     * Sign the code the guest shows staff for the booking.
     */
    public function forBooking(Booking $booking): string
    {
        return $this->sign(self::KIND_BOOKING, $booking->id, self::BOOKING_TTL_SECONDS);
    }

    /**
     * Sign the code the guide's device shows at the stop.
     */
    public function forStop(ItineraryStop $stop): string
    {
        return $this->sign(self::KIND_STOP, $stop->id, self::STOP_TTL_SECONDS);
    }

    /**
     * Get the id of the booking a scanned booking code names.
     *
     * @throws ValidationException for a code that is malformed, tampered with, another agency's, not a booking's, or expired
     */
    public function bookingIdFrom(string $token): int
    {
        return $this->read($token, self::KIND_BOOKING);
    }

    /**
     * Get the id of the stop a scanned stop code names.
     *
     * @throws ValidationException for a code that is malformed, tampered with, another agency's, not a stop's, or expired
     */
    public function stopIdFrom(string $token): int
    {
        return $this->read($token, self::KIND_STOP);
    }

    private function sign(string $kind, int $id, int $ttlSeconds): string
    {
        $payload = $this->encode(json_encode([
            'k' => $kind,
            't' => $this->tenantKey(),
            'id' => $id,
            'exp' => now()->addSeconds($ttlSeconds)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->encode($this->signature($payload));
    }

    /**
     * Check the signature before reading anything from the payload, then that
     * it names this agency and the expected kind, then the expiry.
     *
     * @throws ValidationException
     */
    private function read(string $token, string $kind): int
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || ! hash_equals($this->encode($this->signature($parts[0])), $parts[1])) {
            throw $this->invalid();
        }

        $claims = json_decode((string) $this->decode($parts[0]), true);

        if (! is_array($claims)
            || ($claims['k'] ?? null) !== $kind
            || ($claims['t'] ?? null) !== $this->tenantKey()
            || ! is_int($claims['id'] ?? null)
            || ! is_int($claims['exp'] ?? null)) {
            throw $this->invalid();
        }

        if (now()->getTimestamp() >= $claims['exp']) {
            throw ValidationException::withMessages(['qr' => __('This QR code has expired. Scan a fresh one.')]);
        }

        return $claims['id'];
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['qr' => __('This QR code is not valid here.')]);
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key(), true);
    }

    /**
     * The current agency's signing key.
     */
    private function key(): string
    {
        return hash_hmac('sha256', 'qr-check-in:'.$this->tenantKey(), (string) config('app.key'), true);
    }

    private function tenantKey(): string
    {
        $tenant = tenant();

        if ($tenant === null) {
            throw new LogicException('QR codes are signed and read inside an agency.');
        }

        return (string) $tenant->getTenantKey();
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function decode(string $encoded): string|false
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
