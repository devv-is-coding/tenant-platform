<?php

namespace App\Actions\Agency;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveTeamMemberAction
{
    use PasswordValidationRules, ProfileValidationRules, RecordsAuditEvents;

    /**
     * Add a manager or staff member to the agency's team.
     *
     * The account is central, like every user, however it is created: the
     * User model stays on the central connection inside an agency. Only the
     * fields below are written, so the agency, the active flag and an admin
     * or super admin role can never come from the form.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(Tenant $tenant, array $input): User
    {
        $validated = Validator::make($input, $this->rules(), $this->messages())->validate();

        return $tenant->getConnection()->transaction(function () use ($tenant, $validated): User {
            $member = User::create([
                ...$this->profileAttributes($validated),
                'password' => $validated['password'],
                'role' => Role::from((int) $validated['role']),
                'tenant_id' => $tenant->id,
                'is_active' => true,
            ]);

            $this->recordAuditEvent(
                AuditAction::UserCreated,
                $member,
                __(':name joined the team as :role.', ['name' => $member->full_name, 'role' => mb_strtolower($member->role->label())]),
            );

            return $member;
        });
    }

    /**
     * Change a team member's details or move them between manager and
     * staff, recording what changed from what. Saving nothing new records
     * nothing.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $member, array $input): User
    {
        $validated = Validator::make($input, $this->rules($member), $this->messages())->validate();

        $member->fill([
            ...$this->profileAttributes($validated),
            'role' => Role::from((int) $validated['role']),
        ]);

        $changes = [];

        foreach (array_keys($member->getDirty()) as $attribute) {
            $old = $member->getOriginal($attribute);
            $new = $member->getAttribute($attribute);

            $changes[$attribute] = [
                'old' => $old instanceof Role ? $old->value : $old,
                'new' => $new instanceof Role ? $new->value : $new,
            ];
        }

        if ($changes === []) {
            return $member;
        }

        return $member->getConnection()->transaction(function () use ($member, $changes): User {
            $member->save();

            $this->recordAuditEvent(
                AuditAction::UserUpdated,
                $member,
                __(':name\'s account was updated.', ['name' => $member->full_name]),
                $changes,
            );

            return $member;
        });
    }

    /**
     * Get the rules for a team member's account: a new one when no member is
     * given, which also needs a password. Public so the team page can share
     * them.
     *
     * The role is always explicit and one of the two the Admin hands out: a
     * blank would otherwise be cast to 0, the super admin.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(?User $member = null): array
    {
        $rules = [
            ...$this->profileRules($member?->id),
            'role' => ['required', Rule::enum(Role::class)->only([Role::Manager, Role::Staff])],
        ];

        if ($member === null) {
            $rules['password'] = $this->passwordRules();
        }

        return $rules;
    }

    /**
     * Get the messages for the team member rules.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'role.required' => __('Choose whether they are a manager or staff.'),
            'role.enum' => __('A team member is a manager or staff. Admins are assigned by the platform.'),
        ];
    }

    /**
     * Get the profile fields to write, with optional ones left empty as NULL.
     *
     * @param  array<string, mixed>  $validated
     * @return array{username: string, email: string, first_name: string, middle_name: string|null, last_name: string, contact_number: string|null}
     */
    private function profileAttributes(array $validated): array
    {
        return [
            'username' => $validated['username'],
            'email' => $validated['email'],
            'first_name' => $validated['first_name'],
            'middle_name' => ($validated['middle_name'] ?? null) ?: null,
            'last_name' => $validated['last_name'],
            'contact_number' => ($validated['contact_number'] ?? null) ?: null,
        ];
    }
}
