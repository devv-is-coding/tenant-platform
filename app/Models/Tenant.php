<?php

namespace App\Models;

use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\TenantStatus;
use App\Services\Plans\PlanFeatureMatrix;
use App\ValueObjects\Money;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Events;

/**
 * Multi-database tenancy requires a tenant model implementing TenantWithDatabase;
 * the package's stock model does not, which is why this app-level subclass exists.
 *
 * HasDatabase supplies the per-tenant database name and connection, HasDomains the
 * domain lookup used by InitializeTenancyByDomain.
 *
 * @property string $id
 * @property string|null $name
 * @property string|null $locality the home locality, a place name such as "Dumaguete City"
 * @property string|null $currency
 * @property TenantStatus $status
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_at
 * @property int|null $status_changed_by
 * @property Carbon|null $deleted_at
 * @property-read Subscription|null $latestSubscription
 * @property-read Subscription|null $liveSubscription
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    /** @use HasFactory<TenantFactory> */
    use HasDatabase, HasDomains, HasFactory, SoftDeletes;

    /**
     * stancl's model events, except that deleting is mapped to a *force*
     * delete only.
     *
     * TenantDeleted runs the DeleteDatabase job, and Eloquent fires `deleted`
     * for a soft delete too, so the stock mapping would drop an agency's whole
     * database when it is only soft-deleted. A soft delete must hide the agency
     * and keep its data; only forceDelete() (tests, data repair, a failed
     * onboarding) may destroy it.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'saving' => Events\SavingTenant::class,
        'saved' => Events\TenantSaved::class,
        'creating' => Events\CreatingTenant::class,
        'created' => Events\TenantCreated::class,
        'updating' => Events\UpdatingTenant::class,
        'updated' => Events\TenantUpdated::class,
        'forceDeleting' => Events\DeletingTenant::class,
        'forceDeleted' => Events\TenantDeleted::class,
    ];

    /**
     * Mirrors the column default, so a freshly created tenant reports Pending
     * without being reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TenantStatus::Pending->value,
    ];

    /**
     * Real table columns; any other attribute is stored in the `data` JSON column.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'status',
            'status_reason',
            'status_changed_at',
            'status_changed_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * Get the agency's admins, managers and staff, the people who run it. The
     * console lists and counts only these, never the agency's customers.
     *
     * @return HasMany<User, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class)->whereIn('role', Role::tenantRoles());
    }

    /**
     * Get the customers who belong to the agency, the ones who signed up in
     * its app or transferred to it.
     *
     * @return HasMany<User, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(User::class)->where('role', Role::Customer);
    }

    /**
     * Get every subscription the agency has held, live or ended.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the agency's most recent subscription. It is the live one when the
     * agency has one, since a new subscription ends the previous one first.
     *
     * @return HasOne<Subscription, $this>
     */
    public function latestSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /**
     * Get the subscription holding the agency's live slot, if any.
     *
     * Read through the unique `active_for_tenant_id` column, which only a live
     * subscription occupies. Once loaded it is kept on the instance, so the
     * tier gates read it once per request.
     *
     * @return HasOne<Subscription, $this>
     */
    public function liveSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'active_for_tenant_id');
    }

    /**
     * Get the tier the agency is on: its live subscription's. Trialing, Active
     * and PastDue count; an agency with no live subscription has no tier.
     */
    public function currentTier(): ?PlanTier
    {
        $subscription = $this->liveSubscription;

        return $subscription !== null && $subscription->status->isLive() ? $subscription->tier : null;
    }

    /**
     * Determine if the agency's tier includes the feature. The one check every
     * gate goes through: route middleware, the @feature Blade directive and
     * the `use-feature` ability.
     */
    public function hasFeature(PlanFeature $feature): bool
    {
        $tier = $this->currentTier();

        return $tier !== null && app(PlanFeatureMatrix::class)->includes($tier, $feature);
    }

    /**
     * Get every feature the agency's tier includes, in the enum's order.
     *
     * @return list<PlanFeature>
     */
    public function features(): array
    {
        $tier = $this->currentTier();

        return $tier === null ? [] : app(PlanFeatureMatrix::class)->featuresFor($tier);
    }

    /**
     * Get every invoice the platform has issued the agency.
     *
     * @return HasMany<SubscriptionInvoice, $this>
     */
    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /**
     * Get every payment the agency has made the platform.
     *
     * @return HasMany<SubscriptionPayment, $this>
     */
    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * Get what the agency still owes across every invoice it has not settled.
     *
     * Summed in minor units by the database, and only ever displayed through
     * Money. Every invoice is billed in the platform's billing currency.
     */
    public function balanceOwed(): Money
    {
        $owedMinor = $this->subscriptionInvoices()
            ->whereIn('status', [SubscriptionInvoiceStatus::Open, SubscriptionInvoiceStatus::PartiallyPaid])
            ->selectRaw('COALESCE(SUM(amount_minor - amount_paid_minor), 0) as owed_minor')
            ->value('owed_minor');

        return Money::ofMinor((int) $owedMinor, config('tenancy.billing_currency'));
    }

    /**
     * Get the platform audit events recorded against the agency.
     *
     * @return HasMany<PlatformAuditEvent, $this>
     */
    public function platformAuditEvents(): HasMany
    {
        return $this->hasMany(PlatformAuditEvent::class);
    }

    /**
     * Match tenants whose id, name or any domain contains the term.
     *
     * The name lives in the `data` JSON column and is lowercased on both sides:
     * MariaDB's json_value() returns a utf8mb4_bin string, so a plain LIKE on it
     * is case-sensitive there even though it is not on SQLite. The extraction
     * is spelled out per driver because raw SQL must be a literal string.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $pattern = '%'.$term.'%';
        $name = $this->getConnection()->getDriverName() === 'sqlite'
            ? "lower(json_extract(data, '$.name'))"
            : "lower(json_value(data, '$.name'))";

        $query->where(fn (Builder $query) => $query
            ->where('id', 'like', $pattern)
            ->orWhereRaw($name.' like ?', [mb_strtolower($pattern)])
            ->orWhereIn('id', Domain::query()->select('tenant_id')->where('domain', 'like', $pattern)));
    }
}
