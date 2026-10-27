<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Backed by the `audit_events.action` and `platform_audit_events.action`
 * string(64) columns.
 *
 * This is the one deliberate exception to the house rule that enums are
 * int-backed: audit actions are a long, open-ended list that must stay readable
 * in raw SQL during an incident, and adding one must not require a migration.
 * The rule exists to avoid DB `enum` columns and keep labels in PHP - a varchar
 * plus a string-backed enum satisfies both.
 *
 * Values are namespaced `domain.verb` so `WHERE action LIKE 'refund.%'` works
 * and `domain()` can group them for a filter UI.
 */
enum AuditAction: string
{
    case AuthLogin = 'auth.login';
    case AuthLoginFailed = 'auth.login_failed';
    case AuthLogout = 'auth.logout';
    case AuthPasswordReset = 'auth.password_reset';
    case AuthTwoFactorEnabled = 'auth.two_factor_enabled';
    case AuthTwoFactorDisabled = 'auth.two_factor_disabled';

    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserDeleted = 'user.deleted';
    case UserRestored = 'user.restored';
    case UserProfileUpdated = 'user.profile_updated';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';

    case TenantCreated = 'tenant.created';
    case TenantActivated = 'tenant.activated';
    case TenantPaused = 'tenant.paused';
    case TenantSuspended = 'tenant.suspended';
    case TenantClosed = 'tenant.closed';
    case TenantReactivated = 'tenant.reactivated';
    case TenantDeleted = 'tenant.deleted';
    case TenantRestored = 'tenant.restored';
    case TenantAdminAssigned = 'tenant.admin_assigned';
    case TenantAdminRevoked = 'tenant.admin_revoked';

    case SubscriptionCreated = 'subscription.created';
    case SubscriptionPlanChanged = 'subscription.plan_changed';
    case SubscriptionCancelled = 'subscription.cancelled';
    case SubscriptionDeleted = 'subscription.deleted';
    case SubscriptionRestored = 'subscription.restored';
    case SubscriptionMarkedPastDue = 'subscription.past_due';
    case SubscriptionPastDueCleared = 'subscription.past_due_cleared';
    case SubscriptionInvoiceIssued = 'subscription.invoice_issued';
    case SubscriptionInvoiceVoided = 'subscription.invoice_voided';
    case SubscriptionInvoiceDeleted = 'subscription.invoice_deleted';
    case SubscriptionInvoiceRestored = 'subscription.invoice_restored';
    case SubscriptionPaymentRecorded = 'subscription.payment_recorded';
    case SubscriptionPaymentDeleted = 'subscription.payment_deleted';
    case SubscriptionPaymentRestored = 'subscription.payment_restored';
    case SubscriptionPaymentFailed = 'subscription.payment_failed';

    case PlanCreated = 'plan.created';
    case PlanUpdated = 'plan.updated';
    case PlanDeleted = 'plan.deleted';
    case PlanFeatureMoved = 'plan_feature.moved';

    case RestoreRequested = 'restore.requested';
    case RestoreDeclined = 'restore.declined';

    case SettingsUpdated = 'settings.updated';

    case ItemCreated = 'item.created';
    case ItemUpdated = 'item.updated';
    case ItemDeleted = 'item.deleted';
    case ItemSubmitted = 'item.submitted';
    case ItemApproved = 'item.approved';
    case ItemRejected = 'item.rejected';
    case ItemPublished = 'item.published';
    case ItemUnpublished = 'item.unpublished';

    case MediaUploaded = 'media.uploaded';
    case MediaDeleted = 'media.deleted';

    case OptionCreated = 'option.created';
    case OptionUpdated = 'option.updated';
    case OptionDeleted = 'option.deleted';

    case InventoryBatchAdded = 'inventory.batch_added';
    case InventoryBatchAdjusted = 'inventory.batch_adjusted';
    case InventoryAllocated = 'inventory.allocated';
    case InventoryReleased = 'inventory.released';

    case BookingCreated = 'booking.created';
    case BookingApproved = 'booking.approved';
    case BookingRejected = 'booking.rejected';
    case BookingCancelled = 'booking.cancelled';
    case BookingExpired = 'booking.expired';

    case PaymentRecorded = 'payment.recorded';
    case PaymentLinkGenerated = 'payment.link_generated';
    case PaymentReceived = 'payment.received';
    case PaymentFailed = 'payment.failed';

    case RefundRequested = 'refund.requested';
    case RefundApproved = 'refund.approved';
    case RefundRejected = 'refund.rejected';
    case RefundProcessed = 'refund.processed';

    case ReviewCreated = 'review.created';
    case ReviewApproved = 'review.approved';
    case ReviewRejected = 'review.rejected';
    case ReviewFeatured = 'review.featured';

    case CheckInRecorded = 'checkin.recorded';

    /**
     * Get the human-readable label for the action.
     *
     * Derived from the value rather than matched case by case, because the list
     * is open-ended by design - a `match` over every case would have to be
     * edited every time an action is added, which is the cost this enum exists
     * to avoid.
     */
    public function label(): string
    {
        return __(Str::headline(Str::replace('.', ' ', $this->value)));
    }

    /**
     * Get the domain prefix, so related actions can be grouped or filtered.
     */
    public function domain(): string
    {
        return Str::before($this->value, '.');
    }
}
