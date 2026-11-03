<?php

namespace App\Models;

use App\Enums\AvailabilityMode;
use App\Enums\InventoryBatchReason;
use App\Enums\Role;
use App\ValueObjects\DateRange;
use Database\Factories\InventoryBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * One entry in an option's inventory ledger: units added, or taken away by a
 * negative quantity. An option's capacity is the sum of its live batches and
 * nothing else; there is no stored count to drift.
 *
 * The ledger is append-only: a batch's option, quantity and reason never
 * change once written, so a mistake is undone by a new Correction batch and
 * the history stays readable. A soft-deleted batch no longer counts.
 *
 * Every save refuses a batch on an Unlimited option (it tracks no stock), a
 * quantity of zero or of the wrong sign for its reason, an inverted effective
 * range, and an `added_by` who is not one of this agency's staff. That last
 * check is the fix for defect 8: the legacy stored `added_by = 1`, a user who
 * never existed, and the database cannot refuse it because users are central.
 *
 * @property int $id
 * @property int $option_id
 * @property int $quantity
 * @property InventoryBatchReason $reason
 * @property string|null $notes
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property int|null $added_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Option|null $option
 */
#[Fillable([
    'option_id',
    'quantity',
    'reason',
    'notes',
    'effective_from',
    'effective_to',
    'added_by',
])]
class InventoryBatch extends Model
{
    /** @use HasFactory<InventoryBatchFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Refuse a batch the ledger cannot hold, and any edit to one already written.
     */
    protected static function booted(): void
    {
        static::updating(function (self $batch): void {
            if ($batch->isDirty(['option_id', 'quantity', 'reason'])) {
                throw new LogicException('An inventory batch cannot be changed once written; add a Correction batch instead.');
            }
        });

        static::saving(function (self $batch): void {
            $mode = Option::withTrashed()->whereKey($batch->option_id)->value('availability_mode');

            if ($mode === AvailabilityMode::Unlimited) {
                throw new LogicException('An Unlimited option tracks no stock, so it has no inventory batches.');
            }

            if ($batch->quantity === 0) {
                throw new InvalidArgumentException('A batch adds or removes at least one unit.');
            }

            if ($batch->reason->reducesCapacity() !== $batch->quantity < 0) {
                $sign = $batch->reason->reducesCapacity() ? 'negative' : 'positive';

                throw new InvalidArgumentException("A {$batch->reason->label()} batch has a {$sign} quantity, not {$batch->quantity}.");
            }

            if ($batch->effective_from !== null && $batch->effective_to !== null) {
                DateRange::of($batch->effective_from, $batch->effective_to);
            }

            if ($batch->added_by !== null && $batch->isDirty('added_by') && ! self::isAgencyStaff($batch->added_by)) {
                throw new InvalidArgumentException("User [{$batch->added_by}] is not one of this agency's staff, so cannot have added the batch.");
            }
        });
    }

    /**
     * Determine if the central user is one of the current agency's staff.
     */
    private static function isAgencyStaff(int $userId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->where('tenant_id', tenant()?->getTenantKey())
            ->whereIn('role', Role::tenantRoles())
            ->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reason' => InventoryBatchReason::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'added_by' => 'integer',
        ];
    }

    /**
     * Get the option whose stock the batch changes.
     *
     * @return BelongsTo<Option, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }
}
