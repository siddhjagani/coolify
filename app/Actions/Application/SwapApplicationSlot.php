<?php

namespace App\Actions\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BuildPackTypes;
use App\Exceptions\ApplicationSlotException;
use App\Jobs\FinishApplicationSlotSwapJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use App\Services\Dns\ManagedDnsRecordCleanup;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Swaps a production application with its staging slot: the production traffic (routing) moves to the staging slot.
 *
 * The staging slot takes the production routing first and restarts, while production keeps it, so the production
 * domains are served during the whole swap. FinishApplicationSlotSwapJob then gives production the old staging routing
 * and restarts it, or puts the staging routing back when the staging restart fails. Callers authorize `update` on
 * both applications.
 */
class SwapApplicationSlot
{
    use AsAction;

    /**
     * Application columns that move with the production traffic: the domains with their proxy settings, the network
     * aliases that other containers use, and the HTTP basic auth that protects the domains.
     */
    public const APPLICATION_ROUTING = [
        'fqdn',
        'domain_port_overrides',
        'noindex_domains',
        'redirect',
        'custom_network_aliases',
        'is_http_basic_auth_enabled',
        'http_basic_auth_username',
        'http_basic_auth_password',
        'http_basic_auth_hash_algorithm',
        'http_basic_auth_bcrypt_cost',
        'http_basic_auth_argon2id_memory_cost',
        'http_basic_auth_argon2id_time_cost',
    ];

    /**
     * Settings that move with the production traffic. Automatic deployment moves too, so a push keeps deploying the
     * staging slot after a swap, and so does the traffic weight, so the new production gets the share of production.
     */
    public const SETTINGS_ROUTING = [
        'is_force_https_enabled',
        'is_permanent_redirect_enabled',
        'is_gzip_enabled',
        'is_stripprefix_enabled',
        'traefik_certificate_resolver',
        'is_auto_deploy_enabled',
        'slot_traffic_weight',
    ];

    /**
     * @param  Application  $application  An application of the slot group: production, or the slot to promote.
     * @param  string  $source  Who swapped: `ui` or `api`. Names the audit event.
     * @param  Application|null  $slot  The slot to promote, needed when production has more than one slot.
     * @return string The UUID of the staging slot deployment that starts the swap.
     *
     * @throws ApplicationSlotException when the applications cannot swap now
     */
    public function handle(Application $application, string $source = 'ui', ?Application $slot = null): string
    {
        $application->refresh();
        $production = $application->slotOf ?? $application;
        $slots = $production->slots;
        if ($slots->isEmpty()) {
            throw new ApplicationSlotException('The application has no deployment slot.', 404);
        }
        $staging = match (true) {
            $slot !== null => $slots->firstWhere('id', $slot->id),
            $application->isNot($production) => $application,
            $slots->count() === 1 => $slots->first(),
            default => throw new ApplicationSlotException('The application has more than one slot. Choose the slot to make production.'),
        };
        if ($staging === null) {
            throw new ApplicationSlotException('The slot is not a deployment slot of this application.', 404);
        }

        $lockKey = self::lockKey($production->id);
        $timeout = self::timeoutSeconds($production);
        if (! Cache::add($lockKey, true, $timeout)) {
            throw new ApplicationSlotException('A swap of these deployment slots is already running.', 409);
        }

        try {
            $this->ensureCanSwap($production, $staging);
            $commit = $staging->get_last_successful_deployment(['commit'])->commit;
            $stagingRouting = self::routing($staging);
            $stagingHostnames = app(ManagedDnsRecordCleanup::class)->hostnamesOf($staging);

            self::applyRouting($staging, self::routing($production));
            try {
                $result = queue_application_deployment(
                    application: $staging,
                    deployment_uuid: new_public_id(),
                    commit: $commit,
                    restart_only: true,
                    is_api: $source === 'api',
                );
            } catch (Throwable $e) {
                self::applyRouting($staging, $stagingRouting);
                throw $e;
            }
            if (! isset($result['deployment_uuid'])) {
                self::applyRouting($staging, $stagingRouting);
                throw new ApplicationSlotException($result['message'] ?? 'The staging slot could not be deployed.', 409);
            }

            FinishApplicationSlotSwapJob::dispatch(
                $production->id,
                $staging->id,
                $result['deployment_uuid'],
                $stagingRouting,
                $stagingHostnames,
                now()->addSeconds($timeout)->getTimestamp(),
            )->delay(now()->addSeconds(FinishApplicationSlotSwapJob::POLL_SECONDS));
        } catch (Throwable $e) {
            Cache::forget($lockKey);
            throw $e;
        }

        auditLog("{$source}.application.slot_swap_started", [
            'team_id' => $production->team()?->id,
            'application_uuid' => $production->uuid,
            'application_name' => $production->name,
            'slot_uuid' => $staging->uuid,
            'slot_name' => $staging->name,
            'deployment_uuid' => $result['deployment_uuid'],
        ]);

        return $result['deployment_uuid'];
    }

    /**
     * Why the application cannot have a deployment slot, or null.
     */
    public static function unsupportedReason(Application $application): ?string
    {
        return match (true) {
            $application->build_pack === BuildPackTypes::DOCKERCOMPOSE->value => 'Deployment slots do not support Docker Compose applications, because their domains belong to the services.',
            ! $application->destination instanceof StandaloneDocker => 'Deployment slots need a Docker Engine server. Swarm is not supported.',
            ! $application->settings->is_container_label_readonly_enabled => 'The container labels of the application are managed manually, so its domains cannot move to another application.',
            $application->additional_servers()->exists() => 'Deployment slots do not support applications on more than one server.',
            count($application->ports_mappings_array) > 0 => 'The application maps ports to the host. A host port cannot move to another container without downtime.',
            $application->settings->is_consistent_container_name_enabled && filled($application->settings->custom_internal_name) => 'The application has a custom container name, which cannot move to another container without downtime.',
            default => null,
        };
    }

    /**
     * One swap per slot group at a time, also while the finish job waits. The key uses the production application at
     * the start of the swap.
     */
    public static function lockKey(int $productionId): string
    {
        return "application-slot-swap:{$productionId}";
    }

    /**
     * How long a swap may take: the deployment timeout of the server, plus time for the queue.
     */
    public static function timeoutSeconds(Application $application): int
    {
        return (int) ($application->destination->server->settings->dynamic_timeout ?? 3600) + 600;
    }

    /**
     * @return array{application: array<string, mixed>, settings: array<string, mixed>}
     */
    public static function routing(Application $application): array
    {
        return [
            'application' => $application->only(self::APPLICATION_ROUTING),
            'settings' => $application->settings->only(self::SETTINGS_ROUTING),
        ];
    }

    /**
     * Saves the routing and regenerates the container labels, as the Domains page does. The next deployment applies them.
     *
     * @param  array{application: array<string, mixed>, settings: array<string, mixed>}  $routing
     */
    public static function applyRouting(Application $application, array $routing): void
    {
        $application->settings->forceFill($routing['settings'])->save();
        $application->forceFill($routing['application']);
        $application->custom_labels = base64_encode(implode("\n", generateLabelsApplication($application)));
        $application->save();
    }

    /**
     * @throws ApplicationSlotException
     */
    private function ensureCanSwap(Application $production, Application $staging): void
    {
        if ($production->team()?->id === null || $production->team()->id !== $staging->team()?->id) {
            throw new ApplicationSlotException('The application and its deployment slot must belong to the same team.');
        }
        foreach ([$production, $staging] as $application) {
            if ($reason = self::unsupportedReason($application)) {
                throw new ApplicationSlotException("{$application->name}: {$reason}");
            }
        }
        if ($production->destination->server_id !== $staging->destination->server_id) {
            throw new ApplicationSlotException('The application and its deployment slot must run on the same server, so the domains can move without a DNS change.');
        }
        if (! str($staging->status)->startsWith('running')) {
            throw new ApplicationSlotException('Start the staging slot before you swap.');
        }
        foreach ([$staging, $production] as $application) {
            if ($application->get_last_successful_deployment(['id']) === null) {
                throw new ApplicationSlotException("{$application->name} has no successful deployment. Deploy it before you swap.");
            }
        }
        $busy = ApplicationDeploymentQueue::query()
            ->whereIn('application_id', [$production->id, $staging->id])
            ->whereIn('status', [ApplicationDeploymentStatus::QUEUED->value, ApplicationDeploymentStatus::IN_PROGRESS->value])
            ->exists();
        if ($busy) {
            throw new ApplicationSlotException('A deployment of the application or its slot is queued or running. Swap after it ends.', 409);
        }
    }
}
