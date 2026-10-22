@php($title = __('Dashboard'))

{{--
    Uses the shared app shell so staff get the same navigation and chrome as the
    central app. The shell's navigation reads tenancy()->initialized, so it
    offers only this agency's pages - the settings routes exist centrally only.
--}}
<x-layouts::app :title="$title">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => auth()->user()->first_name]) }}</flux:heading>

        <flux:text>
            {{ __('You are signed in as :role of :tenant.', [
                'role' => auth()->user()->role->label(),
                'tenant' => tenant('name') ?? tenant('id'),
            ]) }}
        </flux:text>

        @if (tenant('status')->isReadOnly())
            <flux:callout variant="warning" icon="lock-closed" class="mt-4" :heading="__('This agency is :status. You can view its records but not change them.', ['status' => mb_strtolower(tenant('status')->label())])">
                @if (tenant('status_reason'))
                    <flux:callout.text>{{ __('Reason: :reason', ['reason' => tenant('status_reason')]) }}</flux:callout.text>
                @endif

                @if (tenant('status')->hidesCustomersAndMoney())
                    <flux:callout.text>{{ __('Customers\' details and money figures are hidden until the agency is on a plan again. Bookings still show their reference, dates and status, so you can honour travel already sold.') }}</flux:callout.text>
                @endif
            </flux:callout>
        @endif
    </div>
</x-layouts::app>
