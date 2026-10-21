{{--
    The super admin's first page, before the login. It is public, so it shows
    no platform data at all: no counts, agency names or activity.
--}}
<x-layouts::auth
    :title="__('Super admin')"
    :panel-title="__('Super admin')"
    :panel-description="__('Onboard travel agencies, manage their subscriptions and billing, and keep an eye on the whole platform from one dashboard.')"
    :panel-notice="__('Restricted area. Activity is attributable to your account.')"
>
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3">
            <flux:badge size="sm" color="amber" icon="shield-check" class="self-center">
                {{ __('Super admin') }}
            </flux:badge>

            <x-auth-header
                :title="__('Welcome to :app', ['app' => config('app.name')])"
                :description="__('The platform behind every agency\'s own branded app. Sign in to reach the dashboard.')"
            />
        </div>

        <flux:button :href="route('admin.login')" variant="primary" icon:trailing="arrow-right" class="w-full" wire:navigate>
            {{ __('Sign in') }}
        </flux:button>

        <flux:text class="text-center text-sm">
            {{ __('Agency staff sign in on their agency\'s own address instead.') }}
        </flux:text>
    </div>
</x-layouts::auth>
