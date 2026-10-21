<?php

use App\Enums\SubscriptionInvoiceStatus;

test('an invoice\'s status follows from what it charges and what has been paid', function (int $amountMinor, int $amountPaidMinor, SubscriptionInvoiceStatus $status) {
    expect(SubscriptionInvoiceStatus::fromAmounts($amountMinor, $amountPaidMinor))->toBe($status);
})->with([
    'nothing paid' => [250000, 0, SubscriptionInvoiceStatus::Open],
    'one cent paid' => [250000, 1, SubscriptionInvoiceStatus::PartiallyPaid],
    'one cent short' => [250000, 249999, SubscriptionInvoiceStatus::PartiallyPaid],
    'paid exactly' => [250000, 250000, SubscriptionInvoiceStatus::Paid],
    'paid over' => [250000, 250001, SubscriptionInvoiceStatus::Paid],
]);
