<?php

namespace App\Actions\Notifications;

use App\Enums\PlatformNoticeType;
use App\Models\PlatformNotice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tell an agency's Admin something about its standing with the platform: a
 * restore request or plan change decided, a subscription past due.
 *
 * Called by the central action that made the decision, inside its own
 * transaction, so the notice stands or falls with it. Written centrally,
 * never into the agency's database (see the `platform_notices` migration),
 * and whatever the agency's plan.
 */
class SendPlatformNoticeAction
{
    /**
     * Write the notice under the type's title. An agency that no longer
     * exists has no one to tell.
     */
    public function handle(?Tenant $tenant, PlatformNoticeType $type, string $message, ?Model $subject = null): ?PlatformNotice
    {
        if ($tenant === null) {
            return null;
        }

        return PlatformNotice::query()->create([
            'tenant_id' => $tenant->id,
            'notice_type' => $type,
            'title' => $type->label(),
            'message' => $message,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);
    }
}
