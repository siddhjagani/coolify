<?php

namespace App\Jobs;

use App\Actions\Application\SwapApplicationSlot;
use App\Enums\ApplicationDeploymentStatus;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ScheduledTask;
use App\Services\Dns\ManagedDnsRecordCleanup;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The second half of SwapApplicationSlot. It waits until the staging slot runs with the production routing, then gives
 * production the old staging routing, swaps the roles and names, and restarts the old production. When the staging
 * restart fails, it puts the staging routing back; production was never changed. It carries the staging routing,
 * which can hold a basic auth password, so the payload is encrypted.
 */
class FinishApplicationSlotSwapJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const POLL_SECONDS = 5;

    public int $maxExceptions = 3;

    /**
     * @param  array{application: array<string, mixed>, settings: array<string, mixed>}  $stagingRouting  The routing of the staging slot before the swap.
     * @param  list<string>  $stagingHostnames  The hostnames of the staging slot before the swap.
     * @param  int  $timeoutAt  Unix time after which the swap counts as failed.
     */
    public function __construct(
        public int $productionId,
        public int $stagingId,
        public string $deploymentUuid,
        public array $stagingRouting,
        public array $stagingHostnames,
        public int $timeoutAt,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->timeoutAt);
    }

    public function handle(): void
    {
        $status = ApplicationDeploymentQueue::query()->where('deployment_uuid', $this->deploymentUuid)->value('status');
        if (in_array($status, [ApplicationDeploymentStatus::QUEUED->value, ApplicationDeploymentStatus::IN_PROGRESS->value], true)) {
            $this->release(self::POLL_SECONDS);

            return;
        }

        $status === ApplicationDeploymentStatus::FINISHED->value ? $this->finish() : $this->revert("the staging slot deployment ended with the status {$status}");
    }

    public function failed(?Throwable $exception): void
    {
        $this->revert($exception?->getMessage() ?: 'the swap timed out');
    }

    private function finish(): void
    {
        [$production, $staging] = $this->pair();
        if ($production === null) {
            return;
        }

        $productionCommit = $production->get_last_successful_deployment(['commit'])?->commit;
        $productionHostnames = app(ManagedDnsRecordCleanup::class)->hostnamesOf($production);
        $productionName = $production->name;
        $stagingName = $staging->name;

        $production->getConnection()->transaction(function () use ($production, $staging, $productionName, $stagingName) {
            // The old staging slot becomes production: the link and the names follow the roles.
            $staging->forceFill(['slot_of_id' => null, 'name' => $productionName])->save();
            $production->forceFill(['slot_of_id' => $staging->id, 'name' => $stagingName]);
            SwapApplicationSlot::applyRouting($production, $this->stagingRouting);

            // Scheduled tasks run in the production role.
            $productionTaskIds = $production->scheduled_tasks()->pluck('id');
            ScheduledTask::query()->where('application_id', $staging->id)->update(['application_id' => $production->id]);
            ScheduledTask::query()->whereKey($productionTaskIds)->update(['application_id' => $staging->id]);
        });

        $teamId = (int) $production->team()?->id;
        // The old production container serves the production domains until this restart, so a failure here causes no downtime.
        try {
            $result = queue_application_deployment(
                application: $production->refresh(),
                deployment_uuid: new_public_id(),
                commit: $productionCommit,
                restart_only: true,
            );
            if (! isset($result['deployment_uuid'])) {
                Log::warning("The old production application {$production->uuid} was not restarted after a slot swap: {$result['message']}");
            }

            $dnsCleanup = app(ManagedDnsRecordCleanup::class);
            $dnsCleanup->queueReleaseOfRemovedHostnames($production, $productionHostnames, $teamId);
            $dnsCleanup->queueReleaseOfRemovedHostnames($staging, $this->stagingHostnames, $teamId);
        } catch (Throwable $e) {
            Log::warning("Finishing the slot swap of {$production->uuid} failed: {$e->getMessage()}");
        }

        auditLog('system.application.slot_swapped', [
            'team_id' => $teamId,
            'application_uuid' => $staging->uuid,
            'application_name' => $staging->name,
            'slot_uuid' => $production->uuid,
            'slot_name' => $production->name,
            'deployment_uuid' => $this->deploymentUuid,
        ]);
        ApplicationStatusChanged::dispatch($teamId);
        Cache::forget(SwapApplicationSlot::lockKey($this->productionId, $this->stagingId));
    }

    /**
     * Puts the staging routing back, unless the swap already finished.
     */
    private function revert(string $reason): void
    {
        [$production, $staging] = $this->pair();
        if ($production === null) {
            return;
        }

        SwapApplicationSlot::applyRouting($staging, $this->stagingRouting);
        auditLog('system.application.slot_swap_reverted', [
            'team_id' => $production->team()?->id,
            'application_uuid' => $production->uuid,
            'application_name' => $production->name,
            'slot_uuid' => $staging->uuid,
            'slot_name' => $staging->name,
            'deployment_uuid' => $this->deploymentUuid,
            'reason' => $reason,
        ], 'warning');
        ApplicationStatusChanged::dispatch($production->team()?->id);
        Cache::forget(SwapApplicationSlot::lockKey($this->productionId, $this->stagingId));
    }

    /**
     * The pair as it was when the swap started, or nulls when an application was deleted or the swap already finished.
     *
     * @return array{0: ?Application, 1: ?Application}
     */
    private function pair(): array
    {
        $production = Application::query()->find($this->productionId);
        $staging = Application::query()->find($this->stagingId);
        if ($production === null || $staging === null || (int) $staging->slot_of_id !== $production->id) {
            Cache::forget(SwapApplicationSlot::lockKey($this->productionId, $this->stagingId));

            return [null, null];
        }

        return [$production, $staging];
    }
}
