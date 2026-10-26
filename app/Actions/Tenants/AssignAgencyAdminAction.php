<?php

namespace App\Actions\Tenants;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AssignAgencyAdminAction
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Give the agency a new admin by creating their account, with the same
     * fields onboarding asks for its first admin.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, Tenant $tenant, array $input): User
    {
        $validated = Validator::make($input, $this->createRules())->validate();

        return $tenant->getConnection()->transaction(function () use ($actor, $tenant, $validated): User {
            // The role is always explicit: the column's old default was super admin.
            $admin = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'role' => Role::Admin,
                'tenant_id' => $tenant->id,
                'is_active' => true,
            ]);

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::TenantAdminAssigned,
                $actor,
                $tenant,
                __(':name assigned as admin.', ['name' => $admin->full_name]),
                subject: $admin,
                context: ['via' => 'created'],
            );

            return $admin;
        });
    }

    /**
     * Make one of the agency's active managers or staff its admin.
     *
     * @param  array<string, mixed>  $input
     */
    public function promote(User $actor, Tenant $tenant, array $input): User
    {
        $validated = Validator::make($input, $this->promoteRules($tenant), [
            'user_id.required' => __('Choose who becomes admin.'),
            'user_id.exists' => __('Choose one of the agency\'s active managers or staff.'),
        ])->validate();

        return $tenant->getConnection()->transaction(function () use ($actor, $tenant, $validated): User {
            $user = $tenant->staff()->whereKey($validated['user_id'])->lockForUpdate()->firstOrFail();
            $previousRole = $user->role;

            $user->update(['role' => Role::Admin]);

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::TenantAdminAssigned,
                $actor,
                $tenant,
                __(':name promoted to admin.', ['name' => $user->full_name]),
                ['role' => ['old' => $previousRole->value, 'new' => Role::Admin->value]],
                subject: $user,
                context: ['via' => 'promoted'],
            );

            return $user;
        });
    }

    /**
     * Get the rules for a new admin account. Public so the console's form can
     * share them.
     *
     * @return array<string, array<int, mixed>>
     */
    public function createRules(): array
    {
        return [
            'first_name' => $this->givenNameRules(),
            'last_name' => $this->givenNameRules(),
            'username' => $this->usernameRules(),
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * Get the rules for promoting someone already at the agency.
     *
     * @return array<string, array<int, mixed>>
     */
    public function promoteRules(Tenant $tenant): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists(User::class, 'id')
                ->where('tenant_id', $tenant->id)
                ->whereIn('role', [Role::Manager->value, Role::Staff->value])
                ->where('is_active', true)
                ->withoutTrashed()],
        ];
    }
}
