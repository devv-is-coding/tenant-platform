<?php

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

/*
 * The platform decides whether an agency operates, never how, so a super admin
 * must not be able to put an operational action - an approval, a refund - into
 * an agency's own audit log.
 */
test('only tenant lifecycle actions can be written into an agency\'s audit log', function (AuditAction $action) {
    // The guard throws before the tenant database is touched.
    Event::fake([TenantCreated::class]);

    expect(fn () => app(AuditRecorder::class)->recordTenantLifecycleNotice($action, User::factory()->superAdmin()->create(), Tenant::factory()->create()))
        ->toThrow(LogicException::class, "The platform may only write tenant lifecycle notices into a tenant's audit log, not [{$action->value}].");
})->with([
    'item approved' => AuditAction::ItemApproved,
    'refund approved' => AuditAction::RefundApproved,
    'booking cancelled' => AuditAction::BookingCancelled,
]);
