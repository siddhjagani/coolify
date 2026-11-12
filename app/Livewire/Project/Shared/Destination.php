<?php

namespace App\Livewire\Project\Shared;

use App\Actions\Application\StopApplicationOneServer;
use App\Actions\Application\SwapApplicationPrimary;
use App\Actions\Docker\GetContainersStatus;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Traits\ListensToTeamChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Destination extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public $resource;

    public Collection $networks;

    public bool $isAutoFailoverEnabled = false;

    public function getListeners()
    {
        return [
            'refresh' => 'mount',
            ...$this->teamChannelListeners([
                'ApplicationStatusChanged' => 'loadData',
                'ServiceStatusChanged' => 'mount',
            ]),
        ];
    }

    public function mount()
    {
        $this->networks = collect([]);
        $this->isAutoFailoverEnabled = $this->resource instanceof Application && (bool) $this->resource->settings?->is_auto_failover_enabled;
        $this->loadData();
    }

    public function loadData()
    {
        $all_networks = collect([]);
        $all_networks = $all_networks->push($this->resource->destination);
        $all_networks = $all_networks->merge($this->resource->additional_networks);

        $this->networks = $this->usableResourceServers()
            ->get()
            ->filter(fn (Server $server) => $server->canHostResources() && Gate::allows('placeResource', $server))
            ->map(function ($server) {
                return $server->standaloneDockers;
            })->flatten();
        $this->networks = $this->networks->reject(function ($network) use ($all_networks) {
            return $all_networks->pluck('id')->contains($network->id);
        });
        $this->networks = $this->networks->reject(function ($network) {
            return $this->resource->destination->server->id == $network->server->id;
        });
        if ($this->resource?->additional_servers?->count() > 0) {
            $this->networks = $this->networks->reject(function ($network) {
                return $this->resource->additional_servers->pluck('id')->contains($network->server->id);
            });
        }
    }

    private function resourceServers(): Builder
    {
        return Server::query()
            ->where('team_id', $this->resource->team()->id)
            ->with('settings', 'standaloneDockers')
            ->orderBy('name');
    }

    private function usableResourceServers(): Builder
    {
        return $this->resourceServers()
            ->whereRelation('settings', 'is_reachable', true)
            ->whereRelation('settings', 'is_usable', true)
            ->whereRelation('settings', 'is_swarm_worker', false)
            ->whereRelation('settings', 'force_disabled', false);
    }

    /**
     * Find a server of the resource team that can take a deployment.
     * Shows an error toast and returns null when the server is not usable right now.
     */
    private function findUsableServer(int $serverId, string $title): ?Server
    {
        $server = $this->resourceServers()->findOrFail($serverId);
        $settings = $server->settings;

        if (! $settings->is_reachable || ! $settings->is_usable) {
            $this->dispatch('error', $title, "The server {$server->name} is not reachable. Check the connection on the server page and try again.");

            return null;
        }

        if ($settings->force_disabled || $settings->is_swarm_worker || ! $server->canHostResources()) {
            $this->dispatch('error', $title, "The server {$server->name} cannot host resources.");

            return null;
        }

        return $server;
    }

    /**
     * Whether the application runs on the server, as its main server or as an additional server.
     */
    private function runsOnServer(Server $server): bool
    {
        return $this->resource->destination->server_id == $server->id
            || $this->resource->additional_networks()->wherePivot('server_id', $server->id)->exists();
    }

    /**
     * Whether the network is the main destination or an additional network of the application on the server.
     */
    private function isAttachedDestination(StandaloneDocker $destination, Server $server): bool
    {
        if ($this->resource->destination_type === StandaloneDocker::class && $this->resource->destination_id == $destination->id) {
            return true;
        }

        return $this->resource->additional_networks()->wherePivot('server_id', $server->id)->whereKey($destination->id)->exists();
    }

    public function stop($serverId)
    {
        try {
            $this->authorize('deploy', $this->resource);
            $server = $this->resourceServers()->findOrFail((int) $serverId);
            if (! $this->runsOnServer($server)) {
                abort(404);
            }
            StopApplicationOneServer::run($this->resource, $server);
            auditLog('ui.application.destination_stopped', [
                'team_id' => $this->resource->team()?->id,
                'application_uuid' => $this->resource->uuid,
                'application_name' => $this->resource->name,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
            ]);
            $this->refreshServers();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function redeploy(int $network_id, int $server_id)
    {
        try {
            $this->authorize('deploy', $this->resource);
            if ($this->resource->additional_servers->count() > 0 && str($this->resource->docker_registry_image_name)->isEmpty()) {
                $this->dispatch('error', 'Failed to deploy.', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/multiple-servers">documentation</a>');

                return;
            }
            $server = $this->findUsableServer($server_id, 'Failed to deploy.');
            if (! $server) {
                return;
            }
            $destination = $server->standaloneDockers->where('id', $network_id)->firstOrFail();
            if (! $this->isAttachedDestination($destination, $server)) {
                abort(404);
            }
            $deployment_uuid = new_public_id();
            $result = queue_application_deployment(
                deployment_uuid: $deployment_uuid,
                application: $this->resource,
                server: $server,
                destination: $destination,
                only_this_server: true,
                no_questions_asked: true,
            );
            if ($result['status'] === 'queue_full') {
                $this->dispatch('error', 'Deployment queue full', $result['message']);

                return;
            }
            if ($result['status'] === 'skipped') {
                $this->dispatch('success', 'Deployment skipped', $result['message']);

                return;
            }

            return redirectRoute($this, 'project.application.deployment.show', [
                'project_uuid' => data_get($this->resource, 'environment.project.uuid'),
                'application_uuid' => data_get($this->resource, 'uuid'),
                'deployment_uuid' => $deployment_uuid,
                'environment_uuid' => data_get($this->resource, 'environment.uuid'),
            ]);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Swap: makes the additional server primary, and the primary server an additional one.
     */
    public function promote(int $network_id, int $server_id)
    {
        try {
            $this->authorize('update', $this->resource);
            $server = $this->findUsableServer($server_id, 'Failed to swap.');
            if (! $server) {
                return;
            }
            $network = StandaloneDocker::query()->where('server_id', $server->id)->findOrFail($network_id);
            if (! $this->resource->additional_networks()->wherePivot('server_id', $server->id)->whereKey($network->id)->exists()) {
                abort(404);
            }
            $dns = SwapApplicationPrimary::run($this->resource, $network, 'ui');
            $this->resource->refresh();
            $this->dispatchDnsResult($dns, $server);
            $this->refreshServers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @param  array{repointed: list<string>, failed: array<string, string>, unmanaged: list<string>}  $dns
     */
    private function dispatchDnsResult(array $dns, Server $server): void
    {
        $title = e($server->name).' is the primary server now.';
        if ($dns['repointed'] !== []) {
            $this->dispatch('success', $title, 'DNS records updated: '.e(implode(', ', $dns['repointed'])).'.');
        } else {
            $this->dispatch('success', $title);
        }
        if ($dns['failed'] !== []) {
            $this->dispatch('error', 'Some DNS records were not updated.', collect($dns['failed'])
                ->map(fn (string $error, string $hostname): string => e("{$hostname}: {$error}"))
                ->implode('<br>'));
        }
        if ($dns['unmanaged'] !== []) {
            $this->dispatch('info', 'Coolify does not manage the DNS of '.e(implode(', ', $dns['unmanaged'])).'. Point it to '.e($server->name).' if traffic should follow the primary server.');
        }
    }

    public function instantSaveAutoFailover(): void
    {
        try {
            $this->authorize('update', $this->resource);
            if (! $this->resource instanceof Application) {
                abort(404);
            }
            if ($this->isAutoFailoverEnabled && ($reason = $this->resource->autoFailoverUnavailableReason())) {
                $this->isAutoFailoverEnabled = false;
                $this->dispatch('error', 'Automatic failover is not available.', e($reason));

                return;
            }
            $this->resource->settings->update(['is_auto_failover_enabled' => $this->isAutoFailoverEnabled]);
            $this->dispatch('success', $this->isAutoFailoverEnabled ? 'Automatic failover enabled.' : 'Automatic failover disabled.');
        } catch (\Throwable $e) {
            $this->isAutoFailoverEnabled = (bool) $this->resource->settings?->is_auto_failover_enabled;
            handleError($e, $this);
        }
    }

    /**
     * The swaps of the application, newest first, from the audit log.
     */
    #[Computed]
    public function recentSwaps(): Collection
    {
        if (! $this->resource instanceof Application) {
            return collect();
        }

        return AuditEvent::query()
            ->where('team_id', $this->resource->team()?->id)
            ->where('resource_type', 'application')
            ->where('resource_uuid', $this->resource->uuid)
            ->where('action', 'primary_swapped')
            ->latest()
            ->limit(5)
            ->get();
    }

    public function refreshServers()
    {
        GetContainersStatus::run($this->resource->destination->server);
        $this->loadData();
        $this->dispatch('refresh');
    }

    public function addServer(int $network_id, int $server_id)
    {
        try {
            $this->authorize('update', $this->resource);
            $server = $this->findUsableServer($server_id, 'Failed to add server.');
            if (! $server) {
                return;
            }
            $this->authorize('placeResource', $server);
            $network = StandaloneDocker::query()->where('server_id', $server->id)->findOrFail($network_id);
            $reason = $this->resource instanceof Application
                ? $this->resource->additionalServersUnavailableReason($server)
                : 'Only applications can use multiple servers.';
            if ($reason) {
                $this->dispatch('error', 'Failed to add server.', $reason);

                return;
            }

            $this->resource->additional_networks()->syncWithoutDetaching([
                $network->id => ['server_id' => $server->id],
            ]);
            $this->dispatch('refresh');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeServer(int $network_id, int $server_id, $password, $selectedActions = [])
    {
        try {
            $this->authorize('update', $this->resource);
            if (! verifyPasswordConfirmation($password, $this)) {
                return 'The provided password is incorrect.';
            }

            if ($this->resource->destination->server->id == $server_id && $this->resource->destination->id == $network_id) {
                $this->dispatch('error', 'You are trying to remove the main server.');

                return;
            }
            $server = $this->resourceServers()->findOrFail($server_id);
            StopApplicationOneServer::run($this->resource, $server);
            $this->resource->additional_networks()
                ->wherePivot('server_id', $server_id)
                ->detach($network_id);
            $this->loadData();
            $this->dispatch('refresh');
            ApplicationStatusChanged::dispatch(data_get($this->resource, 'environment.project.team.id'));

            return true;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
