<?php

namespace App\Listeners;

use App\Enums\ItemStatus;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Events\BookingApproved;
use App\Events\BookingCreated;
use App\Events\BookingRejected;
use App\Events\CustomerRegistered;
use App\Events\ListingDecided;
use App\Events\PaymentLinkGenerated;
use App\Events\PaymentReceived;
use App\Events\ProfileUpdated;
use App\Events\RefundApproved;
use App\Events\RefundProcessed;
use App\Events\RefundRejected;
use App\Events\RefundRequested;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns the agency's domain events into its in-app feed: all twelve legacy
 * notification types (P20), each written from its event and never from UI
 * code. Discovered by its `handle*` methods.
 *
 * The customer hears about their own booking, payments and refunds. What
 * waits on a decision (a new booking, a refund request) also goes to the
 * agency's active Managers and Admins, for the staff bell. Every amount is
 * formatted through Money: the legacy once sent a customer the literal text
 * 30.00999999999999801048033987 (defect 2).
 *
 * Written whatever the agency's plan: the `Notifications` feature gates the
 * bell and the app's feed, which read these (TS-6, CU-9), not the writing.
 */
class WriteNotifications
{
    public function handleBookingCreated(BookingCreated $event): void
    {
        $booking = $event->booking;

        $this->notify([$booking->customer_id], NotificationType::BookingCreated, __('We have your booking :reference for :total, and will let you know once it is confirmed.', [
            'reference' => $booking->reference,
            'total' => $booking->total->format(),
        ]), $booking);

        $this->notify($this->deciders(), NotificationType::BookingCreated, __(':customer booked :reference for :total. It is waiting for approval.', [
            'customer' => $booking->customer_name,
            'reference' => $booking->reference,
            'total' => $booking->total->format(),
        ]), $booking);
    }

    public function handleBookingApproved(BookingApproved $event): void
    {
        $booking = $event->booking;

        $this->notify([$booking->customer_id], NotificationType::BookingApproved, __('Your booking :reference is confirmed.', [
            'reference' => $booking->reference,
        ]), $booking);
    }

    public function handleBookingRejected(BookingRejected $event): void
    {
        $booking = $event->booking;

        $this->notify([$booking->customer_id], NotificationType::BookingRejected, $this->withReason(
            __('Your booking :reference was declined.', ['reference' => $booking->reference]),
            $booking->rejection_reason,
        ), $booking);
    }

    public function handlePaymentReceived(PaymentReceived $event): void
    {
        $booking = $event->payment->booking()->firstOrFail();
        $balance = $booking->balance();

        $this->notify([$booking->customer_id], NotificationType::PaymentReceived, $balance->minor > 0
            ? __('We have received your payment of :amount for :reference. Balance due: :balance.', [
                'amount' => $event->payment->amount->format(),
                'reference' => $booking->reference,
                'balance' => $balance->format(),
            ])
            : __('We have received your payment of :amount. :reference is paid in full.', [
                'amount' => $event->payment->amount->format(),
                'reference' => $booking->reference,
            ]), $booking);
    }

    public function handlePaymentLinkGenerated(PaymentLinkGenerated $event): void
    {
        $this->notify([$event->booking->customer_id], NotificationType::PaymentLinkGenerated, __('Your payment link for the :balance balance of :reference is ready.', [
            'balance' => $event->balance->format(),
            'reference' => $event->booking->reference,
        ]), $event->booking);
    }

    public function handleRefundRequested(RefundRequested $event): void
    {
        $booking = $this->bookingOf($event->refund);

        $this->notify($this->deciders(), NotificationType::RefundRequested, $this->withReason(
            __(':amount refund asked for on :reference.', ['amount' => $event->refund->amount->format(), 'reference' => $booking->reference]),
            $event->refund->reason,
        ), $booking);
    }

    public function handleRefundApproved(RefundApproved $event): void
    {
        $booking = $this->bookingOf($event->refund);

        $this->notify([$booking->customer_id], NotificationType::RefundApproved, __('Your refund of :amount on :reference was approved.', [
            'amount' => $event->refund->amount->format(),
            'reference' => $booking->reference,
        ]), $booking);
    }

    public function handleRefundRejected(RefundRejected $event): void
    {
        $booking = $this->bookingOf($event->refund);

        $this->notify([$booking->customer_id], NotificationType::RefundRejected, $this->withReason(
            __('Your refund request on :reference was declined.', ['reference' => $booking->reference]),
            $event->refund->decision_reason,
        ), $booking);
    }

    public function handleRefundProcessed(RefundProcessed $event): void
    {
        $booking = $this->bookingOf($event->refund);

        $this->notify([$booking->customer_id], NotificationType::RefundProcessed, __(':amount has been refunded on :reference.', [
            'amount' => $event->refund->amount->format(),
            'reference' => $booking->reference,
        ]), $booking);
    }

    public function handleListingDecided(ListingDecided $event): void
    {
        $item = $event->item;

        if ($item->created_by === null) {
            return;
        }

        $this->notify([$item->created_by], NotificationType::Approval, $item->item_status === ItemStatus::Rejected
            ? $this->withReason(__(':item was rejected.', ['item' => $item->name]), $item->rejection_reason)
            : __(':item was approved.', ['item' => $item->name]), $item);
    }

    public function handleCustomerRegistered(CustomerRegistered $event): void
    {
        $this->notify([$event->customer->id], NotificationType::UserRegistered, __('Welcome, :name! Your account has been created.', [
            'name' => $event->customer->first_name,
        ]), $event->customer);
    }

    public function handleProfileUpdated(ProfileUpdated $event): void
    {
        $this->notify([$event->user->id], NotificationType::ProfileUpdated, __('Your profile details were changed. If this was not you, contact the agency.'), $event->user);
    }

    /**
     * Write one notification per recipient, into the current agency's feed.
     * Outside an agency there is no feed to write to.
     *
     * @param  iterable<int>  $userIds
     */
    private function notify(iterable $userIds, NotificationType $type, string $message, ?Model $subject): void
    {
        if (tenant() === null) {
            return;
        }

        foreach (collect($userIds)->unique() as $userId) {
            Notification::query()->create([
                'user_id' => $userId,
                'notification_type' => $type,
                'title' => $type->label(),
                'message' => $message,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
            ]);
        }
    }

    /**
     * The agency's active Managers and Admins, who decide bookings and refunds.
     *
     * @return array<int, int>
     */
    private function deciders(): array
    {
        return User::query()
            ->where('tenant_id', tenant()?->getTenantKey())
            ->whereIn('role', [Role::Admin, Role::Manager])
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id'])
            ->map(fn (User $user): int => $user->id)
            ->all();
    }

    private function bookingOf(Refund $refund): Booking
    {
        return Booking::query()->whereKey($refund->booking_id)->firstOrFail();
    }

    private function withReason(string $message, ?string $reason): string
    {
        return $reason === null || trim($reason) === '' ? $message : $message.' '.__('Reason: :reason', ['reason' => trim($reason)]);
    }
}
