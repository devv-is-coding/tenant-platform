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
 * one. Account security actions, such as locking out a leaver, are the one
 * exception to the status and tier rules (securityAbilities()): an agency
 * must always be able to take access away.
 */
abstract class AgencyPolicy
{
    /**
     * Get the plan feature the policy's records belong to, or null when the
     * records are never plan-gated (an agency's settings, its team accounts).
     */
    abstract protected function feature(): ?PlanFeature;

    /**
     * Get the plan feature one ability needs: the policy's own, unless its
     * records hold fields on more than one tier (the agency's settings hold
     * its operating defaults and its app branding).
     */
    protected function featureFor(string $ability): ?PlanFeature
    {
        return $this->feature();
    }

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
     * Get the abilities that take access away, which neither the agency's
     * status nor its tier refuses. They still need an agency.
     *
     * @return list<string>
     */
    protected function securityAbilities(): array
    {
        return [];
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

        if (in_array($ability, $this->securityAbilities(), true)) {
            return null;
        }

        if ($tenant->status->isReadOnly()) {
            return Response::deny($tenant->status->readOnlyNotice());
        }

        $feature = $this->featureFor($ability);

        if ($feature !== null && ! $tenant->hasFeature($feature)) {
            return Response::deny(__(':feature is available on :tier.', [
                'feature' => $feature->label(),
                'tier' => app(PlanFeatureMatrix::class)->minimumTierFor($feature)->label(),
            ]));
        }

        return null;
    }
}
