<?php

use App\Enums\SubscriptionInvoiceStatus;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    // Only the central tables are exercised, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

test('an invoice is overdue only while it is owed and its due date has passed', function (SubscriptionInvoiceStatus $status, string $dueOn, bool $overdue) {
    travelTo('2026-10-21 09:00:00');
    $invoice = SubscriptionInvoice::factory()->create(['status' => $status, 'due_on' => $dueOn]);

    expect(SubscriptionInvoice::query()->overdue(today())->pluck('id')->all())->toBe($overdue ? [$invoice->id] : [])
        ->and($invoice->isOverdue(today()))->toBe($overdue);
})->with([
    'open, due yesterday' => [SubscriptionInvoiceStatus::Open, '2026-10-20', true],
    'partly paid, due yesterday' => [SubscriptionInvoiceStatus::PartiallyPaid, '2026-10-20', true],
    'open, due today' => [SubscriptionInvoiceStatus::Open, '2026-10-21', false],
    'open, due next week' => [SubscriptionInvoiceStatus::Open, '2026-10-28', false],
    'paid, due last month' => [SubscriptionInvoiceStatus::Paid, '2026-09-20', false],
    'void, due last month' => [SubscriptionInvoiceStatus::Void, '2026-09-20', false],
]);
