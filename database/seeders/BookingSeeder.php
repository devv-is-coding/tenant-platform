<?php

namespace Database\Seeders;

use App\Enums\AvailabilityMode;
use App\Enums\BookingApprovalStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\RefundStatus;
use App\Enums\RefundType;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingSeeder extends Seeder
{
    /**
     * Per-option unit_index counters, reset for every tenant. Safe because, in
     * this mock dataset, no two seeded bookings share an option - a real
     * booking service would instead compute the next free index from the
     * surviving allocation rows.
     *
     * @var array<int, int>
     */
    private array $nextUnitIndex = [];

    /**
     * Seed a handful of bookings per tenant, covering every approval/payment/
     * fulfilment combination the schema supports: a paid-and-checked-in hotel
     * stay, a partially paid in-progress tour, a pending multi-line booking, a
     * rejected booking, and a completed stay that is later partially refunded
     * and reviewed.
     *
     * Run CatalogSeeder first - bookings reference its items and options.
     */
    public function run(): void
    {
        Tenant::query()->each(function (Tenant $tenant): void {
            $tenant->run(function () use ($tenant): void {
                if (DB::table('bookings')->exists()) {
                    return;
                }

                $this->nextUnitIndex = [];

                $staffId = User::query()->where('tenant_id', $tenant->id)->where('role', Role::Staff)->value('id');
                $managerId = User::query()->where('tenant_id', $tenant->id)->where('role', Role::Manager)->value('id');
                $currency = tenant('currency') ?? 'PHP';

                foreach ($this->plan($tenant->id) as $index => $plan) {
                    $this->seedBooking($tenant, $plan, $index + 1, $staffId, $managerId, $currency);
                }
            });
        });
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function seedBooking(Tenant $tenant, array $plan, int $sequence, ?int $staffId, ?int $managerId, string $currency): void
    {
        $customer = User::query()->where('username', $plan['customer'])->first();
        $now = now();
        $createdAt = $now->copy()->subDays(21 - $sequence);

        $lines = [];
        $subtotal = 0;

        foreach ($plan['lines'] as $line) {
            $resolved = $this->resolveLine($line);
            $lines[] = $resolved;
            $subtotal += $resolved['line_total_minor'];
        }

        $reference = sprintf('%s-2026-%04d', $this->tenantCode($tenant->id), $sequence);

        $bookingId = DB::table('bookings')->insertGetId([
            'reference' => $reference,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_email' => $customer->email,
            'approval_status' => $plan['approval']->value,
            'payment_status' => BookingPaymentStatus::Unpaid->value,
            'fulfilment_status' => FulfilmentStatus::NotStarted->value,
            'rejection_reason' => $plan['approval'] === BookingApprovalStatus::Rejected ? $plan['rejection_reason'] : null,
            'currency' => $currency,
            'subtotal_minor' => $subtotal,
            'discount_minor' => 0,
            'total_minor' => $subtotal,
            'amount_paid_minor' => 0,
            'amount_refunded_minor' => 0,
            'approved_at' => $plan['approval'] === BookingApprovalStatus::Approved ? $createdAt->copy()->addHour() : null,
            'rejected_at' => $plan['approval'] === BookingApprovalStatus::Rejected ? $createdAt->copy()->addHour() : null,
            'created_by' => $customer->id,
            'approved_by' => $plan['approval'] === BookingApprovalStatus::Approved ? $managerId : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $lineIds = [];

        foreach ($lines as $line) {
            $lineId = DB::table('booking_lines')->insertGetId([
                'booking_id' => $bookingId,
                'option_id' => $line['option_id'],
                'item_id' => $line['item_id'],
                'item_name' => $line['item_name'],
                'option_name' => $line['option_name'],
                'item_type' => $line['item_type'],
                'availability_mode' => $line['availability_mode'],
                'unit_price_minor' => $line['unit_price_minor'],
                'quantity' => $line['quantity'],
                'nights' => $line['nights'],
                'starts_on' => $line['starts_on'],
                'ends_on' => $line['ends_on'],
                'line_total_minor' => $line['line_total_minor'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $lineIds[] = $lineId;

            if ($plan['approval'] !== BookingApprovalStatus::Rejected) {
                $this->allocate($line, $bookingId, $lineId);
            }
        }

        DB::table('audit_events')->insert([
            'actor_id' => $customer->id,
            'actor_role' => Role::Customer->value,
            'action' => 'booking.created',
            'subject_type' => 'booking',
            'subject_id' => $bookingId,
            'changes' => null,
            'created_at' => $createdAt,
        ]);

        $this->notify($tenant, $customer->id, 4, 'Booking created', "Your booking {$reference} has been received.", $bookingId, $createdAt);

        if ($plan['approval'] === BookingApprovalStatus::Approved) {
            DB::table('audit_events')->insert([
                'actor_id' => $managerId,
                'actor_role' => Role::Manager->value,
                'action' => 'booking.approved',
                'subject_type' => 'booking',
                'subject_id' => $bookingId,
                'changes' => null,
                'created_at' => $createdAt->copy()->addHour(),
            ]);
            $this->notify($tenant, $customer->id, 5, 'Booking approved', "Your booking {$reference} has been approved.", $bookingId, $createdAt->copy()->addHour());
        } elseif ($plan['approval'] === BookingApprovalStatus::Rejected) {
            DB::table('audit_events')->insert([
                'actor_id' => $managerId,
                'actor_role' => Role::Manager->value,
                'action' => 'booking.rejected',
                'subject_type' => 'booking',
                'subject_id' => $bookingId,
                'changes' => json_encode(['approval_status' => ['old' => BookingApprovalStatus::Pending->value, 'new' => BookingApprovalStatus::Rejected->value]]),
                'created_at' => $createdAt->copy()->addHour(),
            ]);
            $this->notify($tenant, $customer->id, 6, 'Booking rejected', "Your booking {$reference} was rejected: {$plan['rejection_reason']}", $bookingId, $createdAt->copy()->addHour());

            return;
        }

        $amountPaid = 0;

        foreach ($plan['payments'] ?? [] as $paymentIndex => $payment) {
            $amountPaid += $payment['amount_minor'];

            DB::table('payments')->insert([
                'booking_id' => $bookingId,
                'reference' => sprintf('PAY-%s-%04d-%d', $this->tenantCode($tenant->id), $sequence, $paymentIndex + 1),
                'amount_minor' => $payment['amount_minor'],
                'currency' => $currency,
                'method' => $payment['method']->value,
                'status' => PaymentRecordStatus::Succeeded->value,
                'paid_at' => $payment['paid_at'],
                'recorded_by' => $payment['method']->isRecordedByStaff() ? $staffId : null,
                'idempotency_key' => $payment['method']->isGatewaySettled() ? "evt_{$reference}_{$paymentIndex}" : null,
                'stripe_payment_intent_id' => $payment['method'] === PaymentMethod::Stripe ? "pi_{$reference}_{$paymentIndex}" : null,
                'created_at' => $payment['paid_at'],
                'updated_at' => $payment['paid_at'],
            ]);

            DB::table('audit_events')->insert([
                'actor_id' => $payment['method']->isRecordedByStaff() ? $staffId : null,
                'actor_role' => $payment['method']->isRecordedByStaff() ? Role::Staff->value : null,
                'action' => 'payment.recorded',
                'subject_type' => 'booking',
                'subject_id' => $bookingId,
                'changes' => null,
                'created_at' => $payment['paid_at'],
            ]);

            $paymentStatus = $this->derivePaymentStatus($amountPaid, 0, $subtotal);
            DB::table('bookings')->where('id', $bookingId)->update([
                'amount_paid_minor' => $amountPaid,
                'payment_status' => $paymentStatus->value,
            ]);

            $this->notify(
                $tenant,
                $customer->id,
                7,
                'Payment received',
                'We have received your payment of '.$this->money($payment['amount_minor']).'.',
                $bookingId,
                $payment['paid_at'],
            );
        }

        if (isset($plan['fulfilment']) && $plan['fulfilment'] !== FulfilmentStatus::NotStarted) {
            DB::table('bookings')->where('id', $bookingId)->update(['fulfilment_status' => $plan['fulfilment']->value]);
        }

        if (isset($plan['checkin'])) {
            $itineraryStopId = $plan['checkin']['stop_sequence'] === null
                ? null
                : DB::table('itinerary_stops')->where('item_id', $lines[0]['item_id'])->where('sequence', $plan['checkin']['stop_sequence'])->value('id');

            DB::table('check_ins')->insert([
                'booking_id' => $bookingId,
                'itinerary_stop_id' => $itineraryStopId,
                'stop_label' => $plan['checkin']['stop_sequence'] === null ? null : DB::table('itinerary_stops')->where('id', $itineraryStopId)->value('location'),
                'customer_id' => $customer->id,
                'checked_in_at' => $plan['checkin']['checked_in_at'],
                'recorded_by' => $itineraryStopId === null ? $staffId : null,
                'created_at' => $plan['checkin']['checked_in_at'],
                'updated_at' => $plan['checkin']['checked_in_at'],
            ]);

            DB::table('audit_events')->insert([
                'actor_id' => $itineraryStopId === null ? $staffId : $customer->id,
                'actor_role' => ($itineraryStopId === null ? Role::Staff : Role::Customer)->value,
                'action' => 'checkin.recorded',
                'subject_type' => 'booking',
                'subject_id' => $bookingId,
                'changes' => null,
                'created_at' => $plan['checkin']['checked_in_at'],
            ]);
        }

        if (isset($plan['review'])) {
            $this->seedReview($tenant, $plan['review'], $bookingId, $lines[0], $customer);
        }

        if (isset($plan['refund'])) {
            $this->seedRefund($tenant, $plan['refund'], $bookingId, $lineIds, $customer, $managerId, $currency, $subtotal, $amountPaid);
        }
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function resolveLine(array $line): array
    {
        $item = DB::table('items')->where('slug', $line['item_slug'])->first();
        $option = DB::table('options')->where('item_id', $item->id)->where('name', $line['option_name'])->first();

        $quantity = $line['quantity'];
        $mode = AvailabilityMode::from((int) $option->availability_mode);

        if ($mode === AvailabilityMode::Nightly) {
            $nights = $line['nights'];
            $startsOn = Carbon::parse($line['starts_on']);
            $endsOn = $startsOn->copy()->addDays($nights);
            $lineTotal = $option->price_minor * $quantity * $nights;
        } elseif ($mode === AvailabilityMode::Capacity) {
            $tourProfile = DB::table('tour_profiles')->where('item_id', $item->id)->first();
            $nights = null;
            $startsOn = Carbon::parse($tourProfile->departs_on);
            $endsOn = Carbon::parse($tourProfile->returns_on);
            $lineTotal = $option->price_minor * $quantity;
        } else {
            $nights = null;
            $startsOn = null;
            $endsOn = null;
            $lineTotal = $option->price_minor * $quantity;
        }

        return [
            'option_id' => $option->id,
            'item_id' => $item->id,
            'item_name' => $item->name,
            'option_name' => $option->name,
            'item_type' => $item->item_type,
            'availability_mode' => $option->availability_mode,
            'unit_price_minor' => $option->price_minor,
            'quantity' => $quantity,
            'nights' => $nights,
            'starts_on' => $startsOn?->toDateString(),
            'ends_on' => $endsOn?->toDateString(),
            'line_total_minor' => $lineTotal,
            'mode' => $mode,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function allocate(array $line, int $bookingId, int $lineId): void
    {
        if ($line['mode'] === AvailabilityMode::Unlimited) {
            return;
        }

        $optionId = $line['option_id'];
        $startIndex = $this->nextUnitIndex[$optionId] ?? 0;

        $occupiedDates = $line['mode'] === AvailabilityMode::Nightly
            ? $this->nightsBetween($line['starts_on'], $line['ends_on'])
            : [$line['starts_on']];

        $now = now();
        $rows = [];

        for ($unit = 0; $unit < $line['quantity']; $unit++) {
            foreach ($occupiedDates as $date) {
                $rows[] = [
                    'option_id' => $optionId,
                    'booking_id' => $bookingId,
                    'booking_line_id' => $lineId,
                    'occupied_on' => $date,
                    'unit_index' => $startIndex + $unit,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('inventory_allocations')->insert($rows);
        $this->nextUnitIndex[$optionId] = $startIndex + $line['quantity'];
    }

    /**
     * @return list<string>
     */
    private function nightsBetween(string $startsOn, string $endsOn): array
    {
        $dates = [];
        $cursor = Carbon::parse($startsOn);
        $end = Carbon::parse($endsOn);

        while ($cursor->lt($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @param  array<string, mixed>  $review
     * @param  array<string, mixed>  $line
     */
    private function seedReview(Tenant $tenant, array $review, int $bookingId, array $line, User $customer): void
    {
        $now = now();

        DB::table('reviews')->insert([
            'booking_id' => $bookingId,
            'item_id' => $line['item_id'],
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'title' => $review['title'],
            'content' => $review['content'],
            'rating' => $review['rating'],
            'is_approved' => true,
            'is_featured' => $review['is_featured'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('audit_events')->insert([
            'actor_id' => $customer->id,
            'actor_role' => Role::Customer->value,
            'action' => 'review.created',
            'subject_type' => 'booking',
            'subject_id' => $bookingId,
            'changes' => null,
            'created_at' => $now,
        ]);

        DB::connection(config('tenancy.database.central_connection'))->table('listings')
            ->where('tenant_id', $tenant->id)
            ->where('item_id', $line['item_id'])
            ->update([
                'rating_avg' => sprintf('%.2f', $review['rating']),
                'reviews_count' => 1,
                'synced_at' => $now,
            ]);
    }

    /**
     * @param  array<string, mixed>  $refund
     * @param  list<int>  $lineIds
     */
    private function seedRefund(
        Tenant $tenant,
        array $refund,
        int $bookingId,
        array $lineIds,
        User $customer,
        ?int $managerId,
        string $currency,
        int $subtotal,
        int $amountPaid,
    ): void {
        $now = now();
        $lineId = $lineIds[$refund['line_index']];

        $refundId = DB::table('refunds')->insertGetId([
            'booking_id' => $bookingId,
            'payment_id' => null,
            'type' => RefundType::Partial->value,
            'status' => RefundStatus::Requested->value,
            'amount_minor' => $refund['amount_minor'],
            'currency' => $currency,
            'reason' => $refund['reason'],
            'requested_by' => $customer->id,
            'requested_at' => $now->copy()->subDays(2),
            'created_at' => $now->copy()->subDays(2),
            'updated_at' => $now->copy()->subDays(2),
        ]);

        $this->notify($tenant, $customer->id, 9, 'Refund requested', 'Your refund request for '.$this->money($refund['amount_minor']).' has been received.', $bookingId, $now->copy()->subDays(2));

        DB::table('refunds')->where('id', $refundId)->update([
            'status' => RefundStatus::Processed->value,
            'decided_by' => $managerId,
            'decided_at' => $now->copy()->subDay(),
            'processed_at' => $now,
            'stripe_refund_id' => "re_{$bookingId}_{$refundId}",
            'updated_at' => $now,
        ]);

        DB::table('audit_events')->insert([
            [
                'actor_id' => $managerId,
                'actor_role' => Role::Manager->value,
                'action' => 'refund.approved',
                'subject_type' => 'booking',
                'subject_id' => $bookingId,
                'changes' => null,
                'created_at' => $now->copy()->subDay(),
            ],
            [
                'actor_id' => $managerId,
                'actor_role' => Role::Manager->value,
                'action' => 'refund.processed',
                'subject_type' => 'booking',
                'subject_id' => $bookingId,
                'changes' => null,
                'created_at' => $now,
            ],
        ]);

        $this->notify($tenant, $customer->id, 10, 'Refund approved', 'Your refund of '.$this->money($refund['amount_minor']).' has been approved.', $bookingId, $now->copy()->subDay());
        $this->notify($tenant, $customer->id, 12, 'Refund processed', 'Your refund of '.$this->money($refund['amount_minor']).' has been processed.', $bookingId, $now);

        // Approval is what returns the inventory: hard-delete the released
        // unit's allocation rows so its unit_index becomes immediately reusable.
        DB::table('inventory_allocations')
            ->where('booking_line_id', $lineId)
            ->where('unit_index', $refund['unit_index_released'])
            ->delete();

        $amountRefunded = $refund['amount_minor'];
        $paymentStatus = $this->derivePaymentStatus($amountPaid, $amountRefunded, $subtotal);

        DB::table('bookings')->where('id', $bookingId)->update([
            'amount_refunded_minor' => $amountRefunded,
            'payment_status' => $paymentStatus->value,
        ]);
    }

    /**
     * Derive a booking's payment status from its amounts, never assigned
     * directly - the same pure-function rule the real service layer will use.
     */
    private function derivePaymentStatus(int $amountPaid, int $amountRefunded, int $total): BookingPaymentStatus
    {
        if ($amountRefunded > 0 && $amountRefunded < $amountPaid) {
            return BookingPaymentStatus::PartiallyRefunded;
        }

        if ($amountRefunded > 0 && $amountRefunded >= $amountPaid) {
            return BookingPaymentStatus::Refunded;
        }

        if ($amountPaid >= $total && $total > 0) {
            return BookingPaymentStatus::Paid;
        }

        if ($amountPaid > 0) {
            return BookingPaymentStatus::PartiallyPaid;
        }

        return BookingPaymentStatus::Unpaid;
    }

    private function notify(Tenant $tenant, int $userId, int $notificationType, string $title, string $message, int $bookingId, string|CarbonInterface $createdAt): void
    {
        DB::connection(config('tenancy.database.central_connection'))->table('notifications')->insert([
            'user_id' => $userId,
            'tenant_id' => $tenant->id,
            'notification_type' => $notificationType,
            'title' => $title,
            'message' => $message,
            'subject_type' => 'booking',
            'subject_id' => $bookingId,
            'is_read' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function money(int $minor): string
    {
        return '₱'.number_format($minor / 100, 2);
    }

    private function tenantCode(string $tenantId): string
    {
        return match ($tenantId) {
            'dumaguete' => 'DGTE',
            'tanjay' => 'TNJY',
            'dauin' => 'DAUN',
            'bayawan' => 'BYWN',
            default => 'ZMB',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plan(string $tenantId): array
    {
        return match ($tenantId) {
            'dumaguete' => $this->dumagueteBookings(),
            'tanjay' => $this->tanjayBookings(),
            'dauin' => $this->dauinBookings(),
            'bayawan' => $this->bayawanBookings(),
            default => $this->zamboanguitaBookings(),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dumagueteBookings(): array
    {
        return [
            [
                'customer' => 'beatriz',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'hotel-palwa', 'option_name' => 'Room 101', 'quantity' => 2, 'nights' => 3, 'starts_on' => '2026-12-01'],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Cash, 'amount_minor' => 47760, 'paid_at' => '2026-10-15 10:22:00'],
                    ['method' => PaymentMethod::Stripe, 'amount_minor' => 71640, 'paid_at' => '2026-10-20 09:05:00'],
                ],
                'fulfilment' => FulfilmentStatus::CheckedIn,
                'checkin' => ['stop_sequence' => null, 'checked_in_at' => '2026-12-01 14:12:00'],
            ],
            [
                'customer' => 'tomas',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'japan-discovery', 'option_name' => 'Couple', 'quantity' => 1],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Cash, 'amount_minor' => 27960, 'paid_at' => '2026-10-11 10:22:00'],
                ],
                'fulfilment' => FulfilmentStatus::CheckedIn,
                'checkin' => ['stop_sequence' => 1, 'checked_in_at' => '2026-12-02 09:40:00'],
            ],
            [
                'customer' => 'carmela',
                'approval' => BookingApprovalStatus::Pending,
                'lines' => [
                    ['item_slug' => 'japan-discovery', 'option_name' => 'Solo', 'quantity' => 1],
                    ['item_slug' => 'japan-evisa', 'option_name' => 'e-Visa', 'quantity' => 1],
                ],
                'payments' => [],
            ],
            [
                'customer' => 'julio',
                'approval' => BookingApprovalStatus::Rejected,
                'rejection_reason' => 'Departure is full; offered 15 December instead.',
                'lines' => [
                    ['item_slug' => 'japan-discovery', 'option_name' => 'Family (5)', 'quantity' => 1],
                ],
                'payments' => [],
            ],
            [
                'customer' => 'beatriz',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'hotel-palwa', 'option_name' => 'Standard Double, 2 Queen Beds', 'quantity' => 2, 'nights' => 2, 'starts_on' => '2027-01-05'],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Stripe, 'amount_minor' => 64000, 'paid_at' => '2026-10-16 11:00:00'],
                ],
                'fulfilment' => FulfilmentStatus::Completed,
                'checkin' => ['stop_sequence' => null, 'checked_in_at' => '2027-01-05 14:05:00'],
                'review' => ['rating' => 5, 'title' => 'Worth every peso', 'content' => 'Hokkaido in December was extraordinary.', 'is_featured' => true],
                'refund' => ['line_index' => 0, 'unit_index_released' => 1, 'amount_minor' => 32000, 'reason' => 'Travelling companion hospitalised; requesting one place back.'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function zamboanguitaBookings(): array
    {
        return [
            [
                'customer' => 'nadia',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'zamboanguita-dive-resort', 'option_name' => 'Garden View Room', 'quantity' => 1, 'nights' => 3, 'starts_on' => '2026-11-25'],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Cash, 'amount_minor' => 264000, 'paid_at' => '2026-10-12 10:00:00'],
                    ['method' => PaymentMethod::Stripe, 'amount_minor' => 396000, 'paid_at' => '2026-10-18 09:15:00'],
                ],
                'fulfilment' => FulfilmentStatus::CheckedIn,
                'checkin' => ['stop_sequence' => null, 'checked_in_at' => '2026-11-25 13:50:00'],
            ],
            [
                'customer' => 'beatriz',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'siquijor-island-hopping', 'option_name' => 'Couple', 'quantity' => 1],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Cash, 'amount_minor' => 91960, 'paid_at' => '2026-10-13 10:22:00'],
                ],
                'fulfilment' => FulfilmentStatus::CheckedIn,
                'checkin' => ['stop_sequence' => 1, 'checked_in_at' => '2026-11-20 08:30:00'],
            ],
            [
                'customer' => 'tomas',
                'approval' => BookingApprovalStatus::Pending,
                'lines' => [
                    ['item_slug' => 'siquijor-island-hopping', 'option_name' => 'Solo', 'quantity' => 1],
                    ['item_slug' => 'south-korea-evisa', 'option_name' => 'K-ETA', 'quantity' => 1],
                ],
                'payments' => [],
            ],
            [
                'customer' => 'carmela',
                'approval' => BookingApprovalStatus::Rejected,
                'rejection_reason' => 'Departure is full; offered the following week instead.',
                'lines' => [
                    ['item_slug' => 'siquijor-island-hopping', 'option_name' => 'Group (5)', 'quantity' => 1],
                ],
                'payments' => [],
            ],
            [
                'customer' => 'nadia',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'zamboanguita-dive-resort', 'option_name' => 'Beachfront Cabana', 'quantity' => 2, 'nights' => 2, 'starts_on' => '2027-01-08'],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Stripe, 'amount_minor' => 1400000, 'paid_at' => '2026-10-17 11:00:00'],
                ],
                'fulfilment' => FulfilmentStatus::Completed,
                'checkin' => ['stop_sequence' => null, 'checked_in_at' => '2027-01-08 14:00:00'],
                'review' => ['rating' => 5, 'title' => 'Best dive base in Negros Oriental', 'content' => 'The house reef alone is worth the trip.', 'is_featured' => true],
                'refund' => ['line_index' => 0, 'unit_index_released' => 1, 'amount_minor' => 700000, 'reason' => 'Dive buddy had to cancel; requesting one cabana back.'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tanjayBookings(): array
    {
        return [
            [
                'customer' => 'julio',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'tanjay-heritage-hotel', 'option_name' => 'Deluxe Room', 'quantity' => 2, 'nights' => 3, 'starts_on' => '2026-11-28'],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Cash, 'amount_minor' => 432000, 'paid_at' => '2026-10-14 10:30:00'],
                    ['method' => PaymentMethod::Stripe, 'amount_minor' => 648000, 'paid_at' => '2026-10-19 09:45:00'],
                ],
                'fulfilment' => FulfilmentStatus::CheckedIn,
                'checkin' => ['stop_sequence' => null, 'checked_in_at' => '2026-11-28 14:10:00'],
            ],
            [
                'customer' => 'nadia',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'seoul-busan-explorer', 'option_name' => 'Solo', 'quantity' => 1],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Cash, 'amount_minor' => 235960, 'paid_at' => '2026-10-15 10:22:00'],
                ],
                'fulfilment' => FulfilmentStatus::CheckedIn,
                'checkin' => ['stop_sequence' => 1, 'checked_in_at' => '2027-01-10 09:00:00'],
            ],
            [
                'customer' => 'beatriz',
                'approval' => BookingApprovalStatus::Pending,
                'lines' => [
                    ['item_slug' => 'seoul-busan-explorer', 'option_name' => 'Couple', 'quantity' => 1],
                    ['item_slug' => 'taiwan-evisa', 'option_name' => 'e-Visa', 'quantity' => 1],
                ],
                'payments' => [],
            ],
            [
                'customer' => 'tomas',
                'approval' => BookingApprovalStatus::Rejected,
                'rejection_reason' => 'Departure is full; offered the following month instead.',
                'lines' => [
                    ['item_slug' => 'seoul-busan-explorer', 'option_name' => 'Family (5)', 'quantity' => 1],
                ],
                'payments' => [],
            ],
            [
                'customer' => 'julio',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'tanjay-heritage-hotel', 'option_name' => 'Suite', 'quantity' => 2, 'nights' => 2, 'starts_on' => '2027-01-12'],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Stripe, 'amount_minor' => 1280000, 'paid_at' => '2026-10-16 11:20:00'],
                ],
                'fulfilment' => FulfilmentStatus::Completed,
                'checkin' => ['stop_sequence' => null, 'checked_in_at' => '2027-01-12 14:00:00'],
                'review' => ['rating' => 4, 'title' => 'Lovely courtyard', 'content' => 'Quiet, central, and the breakfast was generous.', 'is_featured' => false],
                'refund' => ['line_index' => 0, 'unit_index_released' => 1, 'amount_minor' => 640000, 'reason' => 'Travel plans changed; requesting one suite back.'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dauinBookings(): array
    {
        return [
            [
                'customer' => 'rafael',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'dauin-beach-villas', 'option_name' => 'Garden Room', 'quantity' => 1, 'nights' => 3, 'starts_on' => '2026-12-05'],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Cash, 'amount_minor' => 300000, 'paid_at' => '2026-10-14 10:00:00'],
                    ['method' => PaymentMethod::Stripe, 'amount_minor' => 450000, 'paid_at' => '2026-10-19 09:30:00'],
                ],
                'fulfilment' => FulfilmentStatus::CheckedIn,
                'checkin' => ['stop_sequence' => null, 'checked_in_at' => '2026-12-05 14:05:00'],
            ],
            [
                'customer' => 'liza',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'apo-island-dive-snorkel', 'option_name' => 'Couple', 'quantity' => 1],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Cash, 'amount_minor' => 100000, 'paid_at' => '2026-10-13 10:22:00'],
                ],
                'fulfilment' => FulfilmentStatus::CheckedIn,
                'checkin' => ['stop_sequence' => 1, 'checked_in_at' => '2026-11-24 07:45:00'],
            ],
            [
                'customer' => 'miguel',
                'approval' => BookingApprovalStatus::Pending,
                'lines' => [
                    ['item_slug' => 'apo-island-dive-snorkel', 'option_name' => 'Solo', 'quantity' => 1],
                    ['item_slug' => 'singapore-evisa', 'option_name' => 'e-Visa', 'quantity' => 1],
                ],
                'payments' => [],
            ],
            [
                'customer' => 'andrea',
                'approval' => BookingApprovalStatus::Rejected,
                'rejection_reason' => 'Departure is full; offered the following week instead.',
                'lines' => [
                    ['item_slug' => 'apo-island-dive-snorkel', 'option_name' => 'Group (5)', 'quantity' => 1],
                ],
                'payments' => [],
            ],
            [
                'customer' => 'rafael',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'dauin-beach-villas', 'option_name' => 'Beach Villa', 'quantity' => 2, 'nights' => 2, 'starts_on' => '2027-01-15'],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Stripe, 'amount_minor' => 1600000, 'paid_at' => '2026-10-17 11:00:00'],
                ],
                'fulfilment' => FulfilmentStatus::Completed,
                'checkin' => ['stop_sequence' => null, 'checked_in_at' => '2027-01-15 14:00:00'],
                'review' => ['rating' => 5, 'title' => 'Perfect base for muck diving', 'content' => 'Great villas, and the dive sites are a short walk away.', 'is_featured' => true],
                'refund' => ['line_index' => 0, 'unit_index_released' => 1, 'amount_minor' => 800000, 'reason' => 'Travel companion cancelled; requesting one villa back.'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bayawanBookings(): array
    {
        return [
            [
                'customer' => 'paolo',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'bayawan-sunrise-hotel', 'option_name' => 'Standard Room', 'quantity' => 2, 'nights' => 2, 'starts_on' => '2026-12-10'],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Cash, 'amount_minor' => 320000, 'paid_at' => '2026-10-15 10:30:00'],
                    ['method' => PaymentMethod::Stripe, 'amount_minor' => 480000, 'paid_at' => '2026-10-20 09:10:00'],
                ],
                'fulfilment' => FulfilmentStatus::CheckedIn,
                'checkin' => ['stop_sequence' => null, 'checked_in_at' => '2026-12-10 14:15:00'],
            ],
            [
                'customer' => 'nadia',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'mabinay-caves-adventure', 'option_name' => 'Couple', 'quantity' => 1],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Cash, 'amount_minor' => 90000, 'paid_at' => '2026-10-16 10:22:00'],
                ],
                'fulfilment' => FulfilmentStatus::CheckedIn,
                'checkin' => ['stop_sequence' => 1, 'checked_in_at' => '2026-11-27 07:30:00'],
            ],
            [
                'customer' => 'julio',
                'approval' => BookingApprovalStatus::Pending,
                'lines' => [
                    ['item_slug' => 'mabinay-caves-adventure', 'option_name' => 'Solo', 'quantity' => 1],
                    ['item_slug' => 'vietnam-evisa', 'option_name' => 'e-Visa', 'quantity' => 1],
                ],
                'payments' => [],
            ],
            [
                'customer' => 'beatriz',
                'approval' => BookingApprovalStatus::Rejected,
                'rejection_reason' => 'Departure is full; offered the following week instead.',
                'lines' => [
                    ['item_slug' => 'mabinay-caves-adventure', 'option_name' => 'Group (5)', 'quantity' => 1],
                ],
                'payments' => [],
            ],
            [
                'customer' => 'paolo',
                'approval' => BookingApprovalStatus::Approved,
                'lines' => [
                    ['item_slug' => 'bayawan-sunrise-hotel', 'option_name' => 'Family Room', 'quantity' => 2, 'nights' => 2, 'starts_on' => '2027-01-20'],
                ],
                'payments' => [
                    ['method' => PaymentMethod::Stripe, 'amount_minor' => 1360000, 'paid_at' => '2026-10-18 11:00:00'],
                ],
                'fulfilment' => FulfilmentStatus::Completed,
                'checkin' => ['stop_sequence' => null, 'checked_in_at' => '2027-01-20 14:00:00'],
                'review' => ['rating' => 4, 'title' => 'Clean and central', 'content' => 'Spacious family rooms and a generous breakfast.', 'is_featured' => false],
                'refund' => ['line_index' => 0, 'unit_index_released' => 1, 'amount_minor' => 680000, 'reason' => 'Plans changed; requesting one family room back.'],
            ],
        ];
    }
}
