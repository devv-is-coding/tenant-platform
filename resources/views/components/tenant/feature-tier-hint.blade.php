@props(['requiredTier', 'currentTier' => null])

{{--
    Which tier a plan feature needs, against the agency's own. Shared by the
    upsell page (tenant.feature-not-in-plan) and the note on a screen whose
    feature the agency lost (x-tenant.feature-locked).
--}}
<flux:text {{ $attributes }}>
    @if ($currentTier)
        {{ __('Your agency is on :current, which does not include it. Ask the platform to move you to :tier.', ['current' => $currentTier->label(), 'tier' => $requiredTier->label()]) }}
    @else
        {{ __('Your agency has no live plan. Ask the platform to put you on :tier.', ['tier' => $requiredTier->label()]) }}
    @endif
</flux:text>
