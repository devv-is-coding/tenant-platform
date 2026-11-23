<?php

namespace App\Models;

use App\Enums\PlatformNoticeType;
use Database\Factories\PlatformNoticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The platform telling an agency's Admin something about the agency's
 * standing with it, written only by SendPlatformNoticeAction. Central, beside
 * the records it is about (see the migration); the agency's bell reads these
 * with its own `notifications`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property PlatformNoticeType $notice_type
 * @property string $title
 * @property string $message
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property Carbon|null $read_at
 * @property int|null $read_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant|null $tenant
 * @property-read Model|null $subject
 */
#[Fillable([
    'tenant_id',
    'notice_type',
    'title',
    'message',
    'subject_type',
    'subject_id',
])]
class PlatformNotice extends Model
{
    /** @use HasFactory<PlatformNoticeFactory> */
    use CentralConnection, HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notice_type' => PlatformNoticeType::class,
            'subject_id' => 'integer',
            'read_at' => 'datetime',
            'read_by' => 'integer',
        ];
    }

    /**
     * Get the agency told, even if it was deleted since.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    /**
     * Get the central record the notice is about.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to the ones no one at the agency has read yet.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unread(Builder $query): void
    {
        $query->whereNull('read_at');
    }
}
