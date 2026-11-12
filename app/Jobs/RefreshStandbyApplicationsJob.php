<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The server is back after an outage: deploys the applications with automatic failover that use it as a standby
 * (additional) server and were deployed while it was down. A failover must not move traffic to an old version.
 */
class RefreshStandbyApplicationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(public Server $server, public CarbonInterface $downSince)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        foreach ($this->server->additionalApplications() as $application) {
            try {
                $this->refresh($application);
            } catch (Throwable $e) {
                Log::warning('Could not deploy a standby application after a server outage', [
                    'application_uuid' => $application->uuid,
                    'server_uuid' => $this->server->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function refresh(Application $application): void
    {
        if (! $application->settings?->is_auto_failover_enabled) {
            return;
        }

        $missedDeployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', 0)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->where('created_at', '>=', $this->downSince)
            ->latest()
            ->first();
        $destination = $application->additional_networks()->wherePivot('server_id', $this->server->id)->first();
        if ($missedDeployment === null || $destination === null) {
            return;
        }

        queue_application_deployment(
            application: $application,
            deployment_uuid: new_public_id(),
            commit: $missedDeployment->commit,
            server: $this->server,
            destination: $destination,
            only_this_server: true,
            no_questions_asked: true,
        );
    }
}
