<?php

namespace Database\Factories;

use App\Enums\PlatformNoticeType;
use App\Models\PlatformNotice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformNotice>
 */
class PlatformNoticeFactory extends Factory
{
    /**
     * An unread past-due notice for a new agency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'notice_type' => PlatformNoticeType::SubscriptionPastDue,
            'title' => PlatformNoticeType::SubscriptionPastDue->label(),
            'message' => 'Your subscription is past due.',
        ];
    }
}
