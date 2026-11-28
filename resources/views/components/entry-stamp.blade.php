@props([
    'status',
    'date' => null,
    'deleted' => false,
])

@php
    use App\Enums\TenantStatus;

    /** @var TenantStatus $status */
    $ink = $deleted ? 'suspended' : match ($status) {
        TenantStatus::Active => 'active',
        TenantStatus::Pending => 'pending',
        TenantStatus::Paused => 'paused',
        TenantStatus::Suspended => 'suspended',
        TenantStatus::Closed => 'closed',
        TenantStatus::Provisioning => 'setting-up',
    };
    $label = $deleted ? __('Deleted') : $status->label();
@endphp

{{--
    An agency's standing as an entry stamp (DESIGN.md): its status in the
    status's own ink, with the date it took effect beneath when there is one.
--}}
<span {{ $attributes->class('entry-stamp') }} style="--stamp-ink: var(--color-stamp-{{ $ink }})" data-stamp="{{ $ink }}">
    {{ $label }}

    @if ($date)
        <small>{{ __('Since :date', ['date' => $date->format('M j')]) }}</small>
    @endif
</span>
