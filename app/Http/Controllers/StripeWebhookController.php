<?php

namespace App\Http\Controllers;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Enums\RefundStatus;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\Tenant;
use App\Services\Payment\PaymentService;
use App\Services\Payment\RefundService;
use App\Services\Payment\StripeGateway;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Stripe's events for the platform's account, already signature-checked
 * (VerifyStripeWebhookSignature). The only way online money is ever counted:
 * the customer's browser coming back from Stripe proves nothing.
 *
 * One endpoint, on the platform's domain, for every agency, since the
 * platform collects. Each event finds its agency from the metadata the app
 * put on the Stripe object (StripeGateway) and runs inside that agency's
 * database.
 *
 * Stripe resends anything not answered with a 2xx, so an event the app
 * cannot place (another agency gone, a booking deleted, an event it does not
 * handle) is acknowledged and logged rather than failed: resending would
 * never make it placeable. Replays are harmless; each write is idempotent.
 */
class StripeWebhookController extends Controller
{
    use RecordsAuditEvents;

    public function __construct(
        private PaymentService $payments,
        private RefundService $refunds,
        private StripeGateway $gateway,
    ) {}

    /**
     * Handle a signed Stripe event.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $event = $request->json()->all();
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        $tenant = $this->tenantOf($object);

        if ($tenant === null || ! in_array($type, ['invoice.paid', 'invoice.payment_failed', 'refund.updated', 'refund.failed'], true)) {
            return $this->ignored($event, 'not an event for an agency');
        }

        try {
            tenancy()->initialize($tenant);

            return match ($type) {
                'invoice.paid' => $this->invoicePaid($event, $object),
                'invoice.payment_failed' => $this->invoicePaymentFailed($event, $object),
                default => $this->refundUpdated($event, $object),
            };
        } finally {
            tenancy()->end();
        }
    }

    /**
     * An invoice for a booking's balance was paid: record the payment once.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $invoice
     */
    private function invoicePaid(array $event, array $invoice): JsonResponse
    {
        $booking = $this->bookingOf($invoice);
        $amountPaid = is_int($invoice['amount_paid'] ?? null) ? $invoice['amount_paid'] : 0;

        if ($booking === null || $amountPaid <= 0 || ! is_string($invoice['id'] ?? null) || ! is_string($invoice['currency'] ?? null)) {
            return $this->ignored($event, 'no booking or nothing paid');
        }

        $paidAt = $invoice['status_transitions']['paid_at'] ?? $event['created'] ?? null;

        try {
            $this->payments->recordFromGateway(
                $booking,
                Money::ofMinor($amountPaid, strtoupper($invoice['currency'])),
                'stripe:invoice:'.$invoice['id'],
                is_int($paidAt) ? CarbonImmutable::createFromTimestamp($paidAt) : now(),
                $this->gateway->paymentIntentOfInvoice($invoice['id']),
            );
        } catch (InvalidArgumentException $refused) {
            return $this->ignored($event, $refused->getMessage());
        }

        return response()->json(['status' => 'handled']);
    }

    /**
     * A customer's attempt to pay a balance link failed: note it in the
     * agency's log. Nothing about the booking's money changes.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $invoice
     */
    private function invoicePaymentFailed(array $event, array $invoice): JsonResponse
    {
        $booking = $this->bookingOf($invoice);

        if ($booking === null) {
            return $this->ignored($event, 'no booking');
        }

        $this->recordAuditEvent(AuditAction::PaymentFailed, $booking, __('A card payment for :reference failed.', [
            'reference' => $booking->reference,
        ]), context: ['stripe_invoice_id' => $invoice['id'] ?? null, 'stripe_event_id' => $event['id'] ?? null]);

        return response()->json(['status' => 'handled']);
    }

    /**
     * Stripe finished a refund it had pending: settle it.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $stripeRefund
     */
    private function refundUpdated(array $event, array $stripeRefund): JsonResponse
    {
        $refundId = $stripeRefund['metadata']['refund_id'] ?? null;
        $refund = is_string($refundId) ? Refund::query()->find($refundId) : null;

        $outcome = match ($stripeRefund['status'] ?? null) {
            'succeeded' => RefundStatus::Processed,
            'failed', 'canceled' => RefundStatus::Failed,
            default => null,
        };

        if ($refund === null || $outcome === null) {
            return $this->ignored($event, 'no refund, or not finished');
        }

        $this->refunds->settle($refund, $outcome, is_string($stripeRefund['id'] ?? null) ? $stripeRefund['id'] : null);

        return response()->json(['status' => 'handled']);
    }

    /**
     * The agency the Stripe object names in its metadata, if it still exists.
     *
     * @param  array<string, mixed>  $object
     */
    private function tenantOf(array $object): ?Tenant
    {
        $tenantId = $object['metadata']['tenant_id'] ?? null;

        return is_string($tenantId) ? Tenant::query()->find($tenantId) : null;
    }

    /**
     * The booking the Stripe invoice names in its metadata, inside the agency.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function bookingOf(array $invoice): ?Booking
    {
        $reference = $invoice['metadata']['booking_reference'] ?? null;

        return is_string($reference) ? Booking::query()->where('reference', $reference)->first() : null;
    }

    /**
     * Acknowledge an event the app does not act on, so Stripe stops resending it.
     *
     * @param  array<string, mixed>  $event
     */
    private function ignored(array $event, string $why): JsonResponse
    {
        Log::info('Stripe webhook ignored: '.$why, ['event_id' => $event['id'] ?? null, 'type' => $event['type'] ?? null]);

        return response()->json(['status' => 'ignored']);
    }
}
