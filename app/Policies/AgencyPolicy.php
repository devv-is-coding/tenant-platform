<?php

namespace App\Policies;

use App\Enums\PlanFeature;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Plans\PlanFeatureMatrix;
use Illuminate\Auth\Access\Response;

/**
 * The two rules every agency-side policy shares, so each policy that extends
 * this states only its own role rules and the plan feature its records
 * belong to.
 *
 * Every ability except reading is refused:
 *
 *  - while the agency is read-only (Paused, Suspended, Closed). The `tenant`
 *    route group refuses such writes already, but Livewire update requests
 *    never pass through it, so the policy is what holds there;
 *  - while the agency's tier lacks the policy's feature. Reading is never
 *    gated: a downgraded agency still sees its own records, read-only, and
 *    nothing is deleted. The `feature:` route middleware is only for routes
 *    whose whole purpose is the feature.
 *
 * Outside an agency nothing may be written: these records only exist inside
 * one.
 */
abstract class AgencyPolicy
{
    /**
     * Get the plan feature the policy's records belong to, or null when the
     * records are never plan-gated (an agency's settings, its team accounts).
     */
    abstract protected function feature(): ?PlanFeature;

    /**
     * Get the abilities that only read. Every other ability writes.
     *
     * @return list<string>
     */
    protected function readAbilities(): array
    {
        return ['viewAny', 'view'];
    }

    /**
     * Refuse a write the agency's status or tier does not allow, or defer to
     * the policy's own ability.
     */
    public function before(User $user, string $ability): ?Response
    {
        if (in_array($ability, $this->readAbilities(), true)) {
            return null;
        }

        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return Response::deny(__('Agency records can only be changed from within the agency.'));
        }

        if ($tenant->status->isReadOnly()) {
            return Response::deny($tenant->status->readOnlyNotice());
        }

        $feature = $this->feature();

        if ($feature !== null && ! $tenant->hasFeature($feature)) {
            return Response::deny(__(':feature is available on :tier.', [
                'feature' => $feature->label(),
                'tier' => app(PlanFeatureMatrix::class)->minimumTierFor($feature)->label(),
            ]));
        }

        return null;
    }
}
