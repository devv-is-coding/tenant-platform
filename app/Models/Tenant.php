<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
