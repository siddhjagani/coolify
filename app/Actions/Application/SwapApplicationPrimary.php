<?php

namespace App\Actions\Application;

use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\StandaloneDocker;
use App\Notifications\Application\Failover;
use App\Services\Dns\ResourceDnsRecords;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Makes an additional destination the primary destination of an application, and the old primary an additional one.
 * The containers keep running on every server that is up. The managed DNS records of the application follow the
 * primary server, because traffic goes to the primary server.
 */
class SwapApplicationPrimary
{
    use AsAction;

    /**
     * @param  string  $source  Who swapped: `ui`, `api`, or `system` for an automatic failover. Names the audit event.
     * @return array{repointed: list<string>, failed: array<string, string>, unmanaged: list<string>} The DNS records by result.
     *
     * @throws RuntimeException when the destination is not an additional destination of the application
     */
    public function handle(Application $application, StandaloneDocker $target, string $source = 'ui'): array
    {
        // A manual swap and an automatic failover of the same application never run at the same time.
        return Cache::lock("application-swap:{$application->id}", 60)->block(15, function () use ($application, $target, $source): array {
            $application->refresh();
            $previous = $application->destination;
            $previousServer = $previous->server;
            $targetPivot = $application->additional_networks()
                ->wherePivot('server_id', $target->server_id)
                ->whereKey($target->id)
                ->first()?->pivot;
            if ($targetPivot === null) {
                throw new RuntimeException('The destination is not an additional destination of this application.');
            }

            $application->getConnection()->transaction(function () use ($application, $previous, $previousServer, $target, $targetPivot) {
                // Each server keeps its own status: the primary status is on the application, the others on the pivot.
                $previousStatus = $previousServer->isFunctional() ? $application->realStatus() : 'exited';
                $application->update([
                    'destination_id' => $target->id,
                    'destination_type' => StandaloneDocker::class,
                    'status' => $targetPivot->status,
                ]);
                $application->additional_networks()
                    ->wherePivot('server_id', $target->server_id)
                    ->detach($target->id);
                $application->additional_networks()->attach($previous->id, [
                    'server_id' => $previousServer->id,
                    'status' => $previousStatus,
                ]);
            });
            $application->refresh();

            $dns = app(ResourceDnsRecords::class)->repointToCurrentServer($application, $previousServer);

            $teamId = $application->team()?->id;
            auditLog("{$source}.application.primary_swapped", [
                'team_id' => $teamId,
                'application_uuid' => $application->uuid,
                'application_name' => $application->name,
                'from_server_uuid' => $previousServer->uuid,
                'from_server_name' => $previousServer->name,
                'to_server_uuid' => $target->server->uuid,
                'to_server_name' => $target->server->name,
                'dns_repointed' => $dns['repointed'],
                'dns_failed' => array_keys($dns['failed']),
            ], $dns['failed'] === [] ? 'info' : 'warning');
            ApplicationStatusChanged::dispatch($teamId);

            if ($source === 'system') {
                $application->team()?->notify(new Failover($application, $previousServer, $target->server, $dns));
            }

            return $dns;
        });
    }
}
