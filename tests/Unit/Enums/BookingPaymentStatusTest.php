<?php

use App\Enums\BookingPaymentStatus;

test('a booking\'s payment status follows from what it costs, what came in and what went back', function (int $totalMinor, int $paidMinor, int $refundedMinor, BookingPaymentStatus $status) {
    expect(BookingPaymentStatus::fromAmounts($totalMinor, $paidMinor, $refundedMinor))->toBe($status);
})->with([
    'nothing paid' => [59900, 0, 0, BookingPaymentStatus::Unpaid],
    'a free booking, nothing received' => [0, 0, 0, BookingPaymentStatus::Unpaid],
    'one cent paid' => [59900, 1, 0, BookingPaymentStatus::PartiallyPaid],
    'the 400.00 cash deposit' => [59900, 40000, 0, BookingPaymentStatus::PartiallyPaid],
    'one cent short' => [59900, 59899, 0, BookingPaymentStatus::PartiallyPaid],
    'paid exactly' => [59900, 59900, 0, BookingPaymentStatus::Paid],
    'overpaid through the gateway' => [59900, 60000, 0, BookingPaymentStatus::Paid],
    'part of a full payment refunded' => [59900, 59900, 10000, BookingPaymentStatus::PartiallyRefunded],
    'part of a deposit refunded' => [59900, 40000, 10000, BookingPaymentStatus::PartiallyRefunded],
    'one cent left with the agency' => [59900, 59900, 59899, BookingPaymentStatus::PartiallyRefunded],
    'everything paid refunded' => [59900, 59900, 59900, BookingPaymentStatus::Refunded],
    'a refunded deposit' => [59900, 40000, 40000, BookingPaymentStatus::Refunded],
]);
