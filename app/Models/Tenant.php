<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Multi-database tenancy requires a tenant model implementing TenantWithDatabase;
 * the package's stock model does not, which is why this app-level subclass exists.
 *
 * HasDatabase supplies the per-tenant database name and connection, HasDomains the
 * domain lookup used by InitializeTenancyByDomain.
 *
 * @property string $id
 * @property string|null $name
 * @property TenantStatus $status
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_on
 * @property int|null $status_changed_by
 * @property-read Subscription|null $latestSubscription
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    /** @use HasFactory<TenantFactory> */
    use HasDatabase, HasDomains, HasFactory;

    const CREATED_AT = 'created_on';

    const UPDATED_AT = 'updated_on';

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
            'status_changed_on',
            'status_changed_by',
            'created_on',
            'updated_on',
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
            'status_changed_on' => 'datetime',
        ];
    }

    /**
     * Get the tenant's admins, managers and staff.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
     * Get every invoice the platform has issued the agency.
     *
     * @return HasMany<SubscriptionInvoice, $this>
     */
    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
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
