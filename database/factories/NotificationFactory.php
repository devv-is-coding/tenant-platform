<?php

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Notifications live in an agency's own database: create them inside
 * tenancy. A factory notification has no subject.
 *
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * An unread welcome for a new customer of the current agency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn (): int => User::factory()->role(Role::Customer)->create(['tenant_id' => tenant()?->getTenantKey()])->id,
            'notification_type' => NotificationType::UserRegistered,
            'title' => NotificationType::UserRegistered->label(),
            'message' => 'Welcome! Your account has been created.',
        ];
    }
}
