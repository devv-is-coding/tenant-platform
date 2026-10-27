@props(['agency'])

{{--
    Shown at the top of an agency screen while the agency is paused,
    suspended or closed: why it cannot change its records, and, while
    closed, that customers' details and money figures are hidden. The slot
    adds what the screen still allows.

        <x-tenant.read-only-notice :agency="$this->agency" />

    The policies refuse the writes regardless (AgencyPolicy); this only explains.
--}}
@if ($agency->status->isReadOnly())
    <flux:callout variant="warning" icon="lock-closed" :heading="__('This agency is :status. You can view its records but not change them.', ['status' => mb_strtolower($agency->status->label())])" {{ $attributes }}>
        @if ($agency->status_reason)
            <flux:callout.text>{{ __('Reason: :reason', ['reason' => $agency->status_reason]) }}</flux:callout.text>
        @endif

        @if ($agency->status->hidesCustomersAndMoney())
            <flux:callout.text>{{ __('Customers\' details and money figures are hidden until the agency is on a plan again. Bookings still show their reference, dates and status, so you can honour travel already sold.') }}</flux:callout.text>
        @endif

        @if ($slot->isNotEmpty())
            <flux:callout.text>{{ $slot }}</flux:callout.text>
        @endif
    </flux:callout>
@endif
