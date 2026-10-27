<?php

namespace App\Actions\Agency;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Models\User;

class ChangeTeamMemberAccessAction
{
    use RecordsAuditEvents;

    /**
     * Stop a team member signing in. Their open sessions end on their next
     * request (EnsureUserBelongsToTenant).
     *
     * The account is kept, not deleted: they stay on record as the person
     * who did everything they did at the agency.
     */
    public function deactivate(User $member): void
    {
        $this->changeAccess($member, false, AuditAction::UserDeactivated, __(':name was deactivated.', ['name' => $member->full_name]));
    }

    /**
     * Let a deactivated team member sign in again.
     */
    public function reactivate(User $member): void
    {
        $this->changeAccess($member, true, AuditAction::UserReactivated, __(':name was reactivated.', ['name' => $member->full_name]));
    }

    /**
     * Turn the member's access on or off and record it.
     */
    private function changeAccess(User $member, bool $active, AuditAction $action, string $description): void
    {
        $member->getConnection()->transaction(function () use ($member, $active, $action, $description): void {
            $member->update(['is_active' => $active]);

            $this->recordAuditEvent($action, $member, $description, ['is_active' => ['old' => ! $active, 'new' => $active]]);
        });
    }
}
