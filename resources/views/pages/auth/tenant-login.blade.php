{{--
    Served by Fortify's /login on tenant domains. Passkeys are left out because the
    relying party is the central domain, and password reset is left out because its
    token table lives in the central database, which this tenant context cannot see.

    The left panel carries the same title, description and notice as the
    agency's homepage, as the super admin login does with its own.
--}}
@php($agency = tenant('name') ?? tenant('id'))

<x-layouts::auth
    :title="__('Staff log in')"
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
                :title="__('Log in to the staff portal')"
                :description="__('Enter your credentials to reach your agency\'s dashboard')"
            />
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
