<x-layouts::auth
    :title="__('Super admin log in')"
    :panel-title="__('Super admin console')"
    :panel-description="__('Provision tenants, manage their databases, and administer platform-wide settings from a single place.')"
    :panel-notice="__('Restricted area. Activity is attributable to your account.')"
>
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3">
            <flux:badge size="sm" color="amber" icon="shield-check" class="self-center">
                {{ __('Super admin') }}
            </flux:badge>

            <x-auth-header
                :title="__('Log in to the console')"
                :description="__('Enter your credentials to manage tenants and platform settings')"
            />
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify submit-route="admin.passkey.login" />

        <form method="POST" action="{{ route('admin.login.store') }}" class="flex flex-col gap-6">
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
                placeholder="admin@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in to console') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
