<?php

namespace App\Console\Commands;

use App\Concerns\RunsInTenant;
use App\Models\AuditEvent;
use App\Models\PlatformAuditEvent;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

#[Signature('audit:prune')]
#[Description('Soft-delete audit events past the retention period, and delete for good those past the hard limit')]
class PruneAuditEvents extends Command
{
    use RunsInTenant;

    /**
     * Execute the console command.
     *
     * Applies config/audit.php to the platform log and to every agency's own
     * log. Both steps are query-level writes, the only way past the models'
     * append-only guard, which refuses deletes made one model at a time. A
     * soft-deleted event already counts its years from when it happened, so
     * a later run removes it for good on schedule.
     */
    public function handle(): int
    {
        $softDeleteBefore = now()->subYears((int) config('audit.soft_delete_after_years'));
        $hardDeleteBefore = now()->subYears((int) config('audit.hard_delete_after_years'));

        if ($hardDeleteBefore->greaterThan($softDeleteBefore)) {
            throw new LogicException('Audit events must be soft-deleted before they are deleted for good: the hard limit cannot be shorter than the soft one.');
        }

        [$softDeleted, $hardDeleted] = $this->prune(PlatformAuditEvent::class, $softDeleteBefore, $hardDeleteBefore);

        $this->runInEachTenant(function () use ($softDeleteBefore, $hardDeleteBefore, &$softDeleted, &$hardDeleted): void {
            [$agencySoftDeleted, $agencyHardDeleted] = $this->prune(AuditEvent::class, $softDeleteBefore, $hardDeleteBefore);

            $softDeleted += $agencySoftDeleted;
            $hardDeleted += $agencyHardDeleted;
        });

        $this->components->info("Soft-deleted {$softDeleted} audit event(s); deleted {$hardDeleted} for good.");

        return self::SUCCESS;
    }

    /**
     * Hard-delete what is past the hard limit, then soft-delete what is past
     * the soft one.
     *
     * @param  class-string<AuditEvent|PlatformAuditEvent>  $model
     * @return array{int, int} soft-deleted and hard-deleted counts
     */
    private function prune(string $model, CarbonInterface $softDeleteBefore, CarbonInterface $hardDeleteBefore): array
    {
        $hardDeleted = (int) $model::withTrashed()->where('created_at', '<', $hardDeleteBefore)->forceDelete();
        $softDeleted = (int) $model::query()->where('created_at', '<', $softDeleteBefore)->delete();

        return [$softDeleted, $hardDeleted];
    }
}
