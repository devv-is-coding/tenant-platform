<?php

namespace App\Enums;

/**
 * Backed by the `notifications.notification_type` tinyint column.
 *
 * These are the twelve types the legacy actually sent, ported one for one. The
 * legacy stored them as a varchar, and the column had accumulated typos and
 * junk values alongside the real ones.
 *
 * Every type fires from a domain event rather than from UI code, and any money
 * in a body is formatted through Money - the legacy shipped a notification
 * containing the literal text 30.00999999999999801048033987.
 */
enum NotificationType: int
{
    case UserRegistered = 1;
    case ProfileUpdated = 2;
    case Approval = 3;
    case BookingCreated = 4;
    case BookingApproved = 5;
    case BookingRejected = 6;
    case PaymentReceived = 7;
    case PaymentLinkGenerated = 8;
    case RefundRequested = 9;
    case RefundApproved = 10;
    case RefundRejected = 11;
    case RefundProcessed = 12;

    /**
     * Get the human-readable label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::UserRegistered => __('Welcome'),
            self::ProfileUpdated => __('Profile updated'),
            self::Approval => __('Listing approval'),
            self::BookingCreated => __('Booking created'),
            self::BookingApproved => __('Booking approved'),
            self::BookingRejected => __('Booking rejected'),
            self::PaymentReceived => __('Payment received'),
            self::PaymentLinkGenerated => __('Payment link ready'),
            self::RefundRequested => __('Refund requested'),
            self::RefundApproved => __('Refund approved'),
            self::RefundRejected => __('Refund rejected'),
            self::RefundProcessed => __('Refund processed'),
        };
    }
}
