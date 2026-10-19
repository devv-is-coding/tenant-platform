<x-layouts::app :title="__('Platform console')">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl" level="1">{{ __('Platform console') }}</flux:heading>

        <flux:text>
            {{ __('You are signed in as :name, the platform super admin.', ['name' => auth()->user()->full_name]) }}
        </flux:text>
    </div>
</x-layouts::app>
