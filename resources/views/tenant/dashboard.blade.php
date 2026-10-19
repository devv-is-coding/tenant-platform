@php($title = __('Dashboard'))

{{--
    Uses the shared app shell so staff get the same navigation and chrome as the
    central app. The shell's navigation reads tenancy()->initialized, so it
    offers only this agency's pages - the settings routes exist centrally only.
--}}
<x-layouts::app :title="$title">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl">{{ __('Welcome, :name', ['name' => auth()->user()->first_name]) }}</flux:heading>

        <flux:text>
            {{ __('You are signed in as :role of :tenant.', [
                'role' => auth()->user()->role->label(),
                'tenant' => tenant('name') ?? tenant('id'),
            ]) }}
        </flux:text>
    </div>
</x-layouts::app>
