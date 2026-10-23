<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Enums\SubscriptionStatus;
use App\Models\PlanFeatureSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Plans\PlanFeatureMatrix;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;

class MovePlanFeatureAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private PlanFeatureMatrix $planFeatureMatrix,
    ) {}

    /**
     * Move a feature so it starts at another tier, for every agency at once.
     *
     * What a tier includes is read live, so a move up takes the feature from
     * the agencies on the tiers it leaves. That move needs a reason, which the
     * audit log keeps with how many agencies lost the feature. Nothing they
     * made with it is deleted.
     *
     * The override is written onto the feature's existing row, restoring it
     * if it was soft-deleted, since that row still holds the unique feature.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, PlanFeature $feature, array $input): void
    {
        $from = $this->planFeatureMatrix->minimumTierFor($feature);

        $validator = Validator::make($input, $this->rules(), $this->messages());
        $validator->sometimes('move_reason', 'required', fn (Fluent $input): bool => (int) $input->get('minimum_tier') > $from->value);
        $validated = $validator->validate();

        $to = PlanTier::from((int) $validated['minimum_tier']);

        if ($to === $from) {
            return;
        }

        $agenciesLosing = $this->agenciesLosing($feature, $to);

        $setting = PlanFeatureSetting::withTrashed()->updateOrCreate(
            ['feature' => $feature],
            ['minimum_tier' => $to, 'updated_by' => $actor->id],
        );

        if ($setting->trashed()) {
            $setting->restore();
        }

        $this->planFeatureMatrix->forget();

        $reason = trim((string) ($validated['move_reason'] ?? ''));

        $this->auditRecorder->recordPlatformEvent(
            AuditAction::PlanFeatureMoved,
            $actor,
            description: $reason === '' ? null : $reason,
            changes: ['minimum_tier' => ['old' => $from->value, 'new' => $to->value]],
            subject: $setting,
            context: ['feature' => $feature->value, 'agencies_losing' => $agenciesLosing],
        );
    }

    /**
     * Count the agencies with a live subscription that would lose the feature
     * if it started at the given tier: those on a tier that has it now but
     * would not then. Moving a feature down takes it from nobody.
     */
    public function agenciesLosing(PlanFeature $feature, PlanTier $to): int
    {
        $from = $this->planFeatureMatrix->minimumTierFor($feature);

        if (! $to->includes($from) || $to === $from) {
            return 0;
        }

        $liveStatuses = array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $status): bool => $status->isLive());

        return Subscription::query()
            ->whereNotNull('active_for_tenant_id')
            ->whereIn('status', $liveStatuses)
            ->where('tier', '>=', $from->value)
            ->where('tier', '<', $to->value)
            ->count();
    }

    /**
     * Get the validation rules. The reason is also required for a move up,
     * which handle() adds.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'minimum_tier' => ['required', Rule::enum(PlanTier::class)],
            'move_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'minimum_tier.required' => __('Choose the tier the feature starts at.'),
            'minimum_tier.enum' => __('Choose the tier the feature starts at.'),
            'move_reason.required' => __('Say why agencies on a lower tier lose this feature. The reason is kept in the audit log.'),
        ];
    }
}
