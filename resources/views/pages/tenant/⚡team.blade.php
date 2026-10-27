<?php

use App\Actions\Agency\ChangeTeamMemberAccessAction;
use App\Actions\Agency\SaveTeamMemberAction;
use App\Enums\PlanFeature;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| The agency's team: its admins, managers and staff, never its customers.
| The Admin adds, edits, deactivates and reactivates managers and staff;
| admins are listed but are the platform's to assign and revoke.
|
| Adding, editing and reactivating need the Team feature and an agency that
| is not read-only. Deactivating needs neither (UserPolicy), so any agency
| can lock out a leaver.
|
| Every action re-checks the policy, and looks the member up through the
| agency's own staff, since Livewire update requests do not re-run the route
| middleware's role checks on their own.
*/
new #[Title('Team')] class extends Component {
    /**
     * The member the form edits, or null when it adds one. Set only by
     * openEdit(), never by the browser.
     */
    #[Locked]
    public ?int $editing_id = null;

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $username = '';

    public string $email = '';

    public string $contact_number = '';

    public string $role = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * The member the access confirmation is open for, and whether it turns
     * their access on or off. Set only by confirmAccess(), never by the
     * browser.
     */
    #[Locked]
    public ?int $changing_id = null;

    #[Locked]
    public bool $changing_to_active = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAgencyTeam', $this->currentAgency());
    }

    /**
     * Get the agency the team is for.
     */
    #[Computed]
    public function agency(): Tenant
    {
        $agency = $this->currentAgency();
        $this->authorize('viewAgencyTeam', $agency);

        return $agency;
    }

    /**
     * Get the agency's admins, managers and staff, most senior first.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->agency->staff()->orderBy('role')->orderBy('first_name')->orderBy('last_name')->get();
    }

    /**
     * Get the member the access confirmation is open for.
     */
    #[Computed]
    public function changing(): ?User
    {
        return $this->changing_id === null ? null : $this->agency->staff()->find($this->changing_id);
    }

    /**
     * Open the form for adding a manager or staff member.
     */
    public function openCreate(): void
    {
        $this->authorize('create', User::class);

        $this->resetForm();

        Flux::modal('member-form')->show();
    }

    /**
     * Open the form for editing one of the agency's managers or staff.
     */
    public function openEdit(int $id): void
    {
        $member = $this->member($id);
        $this->authorize('update', $member);

        $this->resetForm();
        $this->editing_id = $member->id;
        $this->first_name = $member->first_name;
        $this->middle_name = (string) $member->middle_name;
        $this->last_name = $member->last_name;
        $this->username = $member->username;
        $this->email = $member->email;
        $this->contact_number = (string) $member->contact_number;
        $this->role = (string) $member->role->value;

        Flux::modal('member-form')->show();
    }

    /**
     * Add the member, or save the one the form is editing.
     */
    public function saveMember(SaveTeamMemberAction $saveMember): void
    {
        if ($this->editing_id === null) {
            $this->authorize('create', User::class);

            $member = $saveMember->create($this->agency, $this->only([...array_keys($saveMember->rules()), 'password_confirmation']));
            $message = __(':name joined the team.', ['name' => $member->full_name]);
        } else {
            $member = $this->member($this->editing_id);
            $this->authorize('update', $member);

            $member = $saveMember->update($member, $this->only(array_keys($saveMember->rules($member))));
            $message = __(':name\'s account was saved.', ['name' => $member->full_name]);
        }

        $this->resetForm();
        unset($this->members);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Open the confirmation for turning a member's access off or back on.
     */
    public function confirmAccess(int $id, bool $active): void
    {
        $member = $this->member($id);
        $this->authorize($active ? 'reactivate' : 'deactivate', $member);

        $this->changing_id = $member->id;
        $this->changing_to_active = $active;
        unset($this->changing);

        Flux::modal('change-access')->show();
    }

    /**
     * Turn access off or on for the member the confirmation is open for.
     */
    public function changeAccess(ChangeTeamMemberAccessAction $changeAccess): void
    {
        $member = $this->member((int) $this->changing_id);

        if ($this->changing_to_active) {
            $this->authorize('reactivate', $member);
            $changeAccess->reactivate($member);
            $message = __(':name can sign in again.', ['name' => $member->full_name]);
        } else {
            $this->authorize('deactivate', $member);
            $changeAccess->deactivate($member);
            $message = __(':name can no longer sign in.', ['name' => $member->full_name]);
        }

        $this->reset('changing_id', 'changing_to_active');
        unset($this->members, $this->changing);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Find one of the agency's own staff: another agency's user or one of its
     * customers is not found at all.
     */
    private function member(int $id): User
    {
        return $this->agency->staff()->findOrFail($id);
    }

    /**
     * Empty the member form and its errors.
     */
    private function resetForm(): void
    {
        $this->resetErrorBag();
        $this->reset('editing_id', 'first_name', 'middle_name', 'last_name', 'username', 'email', 'contact_number', 'role', 'password', 'password_confirmation');
    }

    /**
     * Get the agency of the current request.
     */
    private function currentAgency(): Tenant
    {
        $agency = tenant();

        abort_unless($agency instanceof Tenant, 404);

        return $agency;
    }
}; ?>

<div class="flex flex-col gap-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Team') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Who works at :tenant, and who can sign in.', ['tenant' => $this->agency->name ?? $this->agency->id]) }}</flux:text>
        </div>

        @can('create', User::class)
            <flux:button icon="user-plus" variant="primary" wire:click="openCreate" data-test="add-member-button">{{ __('Add member') }}</flux:button>
        @endcan
    </div>

    <x-tenant.read-only-notice :agency="$this->agency">
        {{ __('You can still deactivate someone who has left, so they can no longer sign in.') }}
    </x-tenant.read-only-notice>

    @if (! $this->agency->status->isReadOnly())
        @unlessfeature(PlanFeature::Team)
            <x-tenant.feature-locked :feature="PlanFeature::Team" />
        @endfeature
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('Account') }}</flux:table.column>
            <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->members as $member)
                <flux:table.row :key="$member->id">
                    <flux:table.cell>
                        <span class="font-medium text-zinc-800 dark:text-white">{{ $member->full_name }}</span>
                        <flux:text size="sm">{{ $member->email }}</flux:text>
                    </flux:table.cell>

                    <flux:table.cell>{{ $member->role->label() }}</flux:table.cell>

                    <flux:table.cell class="max-sm:hidden">
                        @if ($member->is_active)
                            <flux:badge color="green" size="sm" inset="top bottom">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm" inset="top bottom">{{ __('Deactivated') }}</flux:badge>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            @can('update', $member)
                                <flux:button size="xs" variant="ghost" icon="pencil-square" inset="top bottom" wire:click="openEdit({{ $member->id }})" :aria-label="__('Edit :name', ['name' => $member->full_name])" />
                            @endcan

                            @can('deactivate', $member)
                                <flux:button size="xs" variant="ghost" icon="user-minus" inset="top bottom" wire:click="confirmAccess({{ $member->id }}, false)" :aria-label="__('Deactivate :name', ['name' => $member->full_name])" />
                            @endcan

                            @can('reactivate', $member)
                                <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" inset="top bottom" wire:click="confirmAccess({{ $member->id }}, true)" :aria-label="__('Reactivate :name', ['name' => $member->full_name])" />
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="py-8 text-center">{{ __('The agency has no team members yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="member-form" class="md:w-[32rem]">
        <form wire:submit="saveMember" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editing_id === null ? __('Add a team member') : __('Edit :name', ['name' => trim($first_name.' '.$last_name)]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('Managers approve listings, bookings and refunds. Staff run the day-to-day desk.') }}</flux:text>
            </div>

            <flux:radio.group wire:model="role" :label="__('Role')" variant="segmented">
                <flux:radio :value="(string) Role::Manager->value" :label="Role::Manager->label()" />
                <flux:radio :value="(string) Role::Staff->value" :label="Role::Staff->label()" />
            </flux:radio.group>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="first_name" :label="__('First name')" required />
                <flux:input wire:model="last_name" :label="__('Last name')" required />
            </div>

            <flux:input wire:model="middle_name" :label="__('Middle name')" :placeholder="__('Optional')" />
            <flux:input wire:model="username" :label="__('Username')" :description="__('Letters, numbers, dashes and underscores only.')" required />
            <flux:input wire:model="email" type="email" :label="__('Email')" required />
            <flux:input wire:model="contact_number" type="tel" :label="__('Contact number')" :placeholder="__('Optional')" />

            @if ($editing_id === null)
                <flux:input wire:model="password" type="password" :label="__('Password')" viewable required />
                <flux:input wire:model="password_confirmation" type="password" :label="__('Confirm password')" viewable required />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ $editing_id === null ? __('Add member') : __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="change-access" class="md:w-[28rem]">
        @if ($this->changing)
            <form wire:submit="changeAccess" class="space-y-6">
                <div>
                    @if ($changing_to_active)
                        <flux:heading size="lg">{{ __('Reactivate :name?', ['name' => $this->changing->full_name]) }}</flux:heading>
                        <flux:text class="mt-2">{{ __('They can sign in again with their existing password, as :role.', ['role' => mb_strtolower($this->changing->role->label())]) }}</flux:text>
                    @else
                        <flux:heading size="lg">{{ __('Deactivate :name?', ['name' => $this->changing->full_name]) }}</flux:heading>
                        <flux:text class="mt-2">{{ __('They can no longer sign in, and any session they have open ends. They stay on record as the person who did everything they did here.') }}</flux:text>
                    @endif
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    @if ($changing_to_active)
                        <flux:button type="submit" variant="primary">{{ __('Reactivate') }}</flux:button>
                    @else
                        <flux:button type="submit" variant="danger">{{ __('Deactivate') }}</flux:button>
                    @endif
                </div>
            </form>
        @endif
    </flux:modal>
</div>
