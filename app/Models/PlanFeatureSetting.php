<?php

namespace App\Models;

use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use Database\Factories\PlanFeatureSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The tier a plan feature starts at, where the super admin has moved it away
 * from its default. A feature with no row starts at its defaultTier(); see the
 * migration.
 *
 * @property int $id
 * @property PlanFeature $feature
 * @property PlanTier $minimum_tier
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'feature',
    'minimum_tier',
    'updated_by',
])]
class PlanFeatureSetting extends Model
{
    /**
     * The table lives in the central database, so the model must stay on the
     * central connection even when an agency's request reads it.
     *
     * @use HasFactory<PlanFeatureSettingFactory>
     */
    use CentralConnection, HasFactory, SoftDeletes;

    /**
     * Named for the rows it holds rather than the model, whose name would
     * clash with the PlanFeature enum.
     *
     * @var string
     */
    protected $table = 'plan_features';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature' => PlanFeature::class,
            'minimum_tier' => PlanTier::class,
        ];
    }
}
