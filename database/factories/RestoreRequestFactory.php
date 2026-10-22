<?php

namespace Database\Factories;

use App\Enums\RestoreRequestStatus;
use App\Enums\Role;
use App\Models\RestoreRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestoreRequest>
 */
class RestoreRequestFactory extends Factory
{
    /**
     * A pending request, from the agency's admin, for one of its deleted staff.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'restorable_type' => 'user',
            'restorable_id' => fn (array $attributes): int => User::factory()->role(Role::Staff)->create([
                'tenant_id' => $attributes['tenant_id'],
                'deleted_at' => now(),
            ])->id,
            'requested_by' => fn (array $attributes): int => User::factory()->role(Role::Admin)->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->id,
            'reason' => fake()->sentence(),
            'status' => RestoreRequestStatus::Pending,
        ];
    }

    /**
     * Indicate that a super admin declined the request.
     */
    public function declined(): static
    {
        return $this->state(fn () => [
            'status' => RestoreRequestStatus::Declined,
            'decided_at' => now(),
            'decision_reason' => fake()->sentence(),
        ]);
    }
}
