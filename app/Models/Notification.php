<?php

namespace App\Models;

use App\Enums\NotificationType;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One entry in the agency's in-app feed: the staff bell (TS-6) and the feed
 * in the customer's app (CU-9). Written only by WriteNotifications, from a
 * domain event, never from UI code.
 *
 * Not Laravel's DatabaseNotification: typed columns rather than one JSON blob,
 * so `$user->notifications()` from the Notifiable trait does not read this
 * table (see the migration). `user_id` is a central user in another database,
 * so not a foreign key.
 *
 * Platform notices to the agency's Admin are not here: they are central
 * (PlatformNotice), and the bell reads both (decided 2026-11-22).
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationType $notification_type
 * @property string $title
 * @property string $message
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property bool $is_read
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Model|null $subject
 */
#[Fillable([
    'user_id',
    'notification_type',
    'title',
    'message',
    'subject_type',
    'subject_id',
])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'notification_type' => NotificationType::class,
            'subject_id' => 'integer',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Get what the notification is about: a booking, payment, refund, item or user.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to the ones not yet read.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unread(Builder $query): void
    {
        $query->where('is_read', false);
    }
}
