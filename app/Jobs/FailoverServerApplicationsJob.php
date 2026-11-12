<?php

namespace App\Jobs;

use App\Actions\Application\SwapApplicationPrimary;
use App\Enums\AvailabilityIncidentReason;
use App\Models\Server;
use App\Notifications\Application\Failover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The server went down: makes a standby server primary for each application on it that has automatic failover.
 * A standby qualifies when its server is up and it runs the application (see Application::failoverTarget()).
 */
class FailoverServerApplicationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 300;

    public function __construct(public Server $server)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    public function handle(): void
    {
        // One failed check, for example a manual validation, is not an outage.
        if ($this->server->isFunctionalAfterRecheck()) {
            return;
        }

        // The application is down since its primary server is; the per-minute check may not have seen it yet.
        $downSince = $this->server->openAvailabilityIncident()?->started_at;

        foreach ($this->server->autoFailoverApplications()->get() as $application) {
            try {
                $application->startAvailabilityIncident(AvailabilityIncidentReason::SERVER_DOWN, $downSince);
                $target = $application->failoverTarget();
                if ($target === null) {
                    $application->team()?->notify(new Failover($application, $this->server, null));

                    continue;
                }
                SwapApplicationPrimary::run($application, $target, 'system');
                // The new primary runs the application: end the outage now, not at the next per-minute check.
                $application->fresh()->recordAvailability();
            } catch (Throwable $e) {
                Log::error('Failover of an application failed', [
                    'application_uuid' => $application->uuid,
                    'server_uuid' => $this->server->uuid,
                    'error' => $e->getMessage(),
                ]);
                $application->team()?->notify(new Failover($application, $this->server, null, error: $e->getMessage()));
            }
        }
    }
}
