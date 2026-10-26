@php($agency = tenant('name') ?? tenant('id'))

{{--
    The agency's first page, before the login. Modelled on the super admin's
    homepage. It is public, so it shows nothing about the agency beyond its
    name: no status, plan, team or records.
--}}
<x-layouts::auth
    :title="$agency"
    :panel-title="$agency"
    :panel-description="__('The staff portal for :agency\'s administrators, managers and staff: the catalog, bookings and the team, in one place.', ['agency' => $agency])"
    :panel-notice="__('Staff only. Activity is attributable to your account.')"
>
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3">
            <flux:badge size="sm" color="sky" icon="building-office" class="self-center">
                {{ $agency }}
            </flux:badge>

            <x-auth-header
                :title="__('Welcome to :agency', ['agency' => $agency])"
                :description="__('The staff portal for this agency. Sign in to reach your dashboard.')"
            />
        </div>

        <flux:button :href="route('login')" variant="primary" icon:trailing="arrow-right" class="w-full" wire:navigate>
            {{ __('Sign in') }}
        </flux:button>

        <flux:text class="text-center text-sm">
            {{ __('Customers book through :agency\'s own app instead.', ['agency' => $agency]) }}
        </flux:text>
    </div>
</x-layouts::auth>
