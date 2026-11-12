<?php

namespace App\Actions\Application;

use App\Exceptions\ApplicationSlotException;
use App\Models\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves how the production traffic of a slot group is split between its applications, and rewrites the proxy routing.
 * Null turns the split off: production serves all the traffic through its labels. Callers authorize `update` on every
 * application of the group.
 */
class SetSlotTrafficSplit
{
    use AsAction;

    /**
     * @param  array<string, int>|null  $weights  Application UUID => percent, for every application of the group.
     *
     * @throws ApplicationSlotException when the weights are not valid
     */
    public function handle(Application $application, ?array $weights, string $source = 'ui'): void
    {
        $group = $application->slotGroup();
        if ($group->isEmpty()) {
            throw new ApplicationSlotException('The application has no deployment slot.', 404);
        }
        $production = $group->first();
        if (Cache::has(SwapApplicationSlot::lockKey($production->id))) {
            throw new ApplicationSlotException('A swap of these deployment slots is running. Change the traffic split after it ends.', 409);
        }
        if ($weights !== null) {
            $this->validate($group, $weights);
        }

        $production->getConnection()->transaction(function () use ($group, $weights) {
            foreach ($group as $member) {
                $member->settings->update(['slot_traffic_weight' => $weights === null ? null : (int) $weights[$member->uuid]]);
            }
        });
        $production->destination->server->setupSlotTrafficSplit();

        auditLog("{$source}.application.slot_traffic_split_changed", [
            'team_id' => $production->team()?->id,
            'application_uuid' => $production->uuid,
            'application_name' => $production->name,
            'weights' => $weights,
        ]);
    }

    /**
     * @param  Collection<int, Application>  $group
     * @param  array<string, mixed>  $weights
     *
     * @throws ApplicationSlotException
     */
    private function validate($group, array $weights): void
    {
        $uuids = $group->pluck('uuid')->all();
        $unknown = array_diff(array_keys($weights), $uuids);
        $missing = array_diff($uuids, array_keys($weights));
        if ($unknown !== [] || $missing !== []) {
            throw new ApplicationSlotException('Give a weight to every application of the slot group, and to no other application.');
        }
        foreach ($weights as $weight) {
            if (! is_int($weight) || $weight < 0 || $weight > 100) {
                throw new ApplicationSlotException('Each weight must be a whole number from 0 to 100.');
            }
        }
        if (array_sum($weights) !== 100) {
            throw new ApplicationSlotException('The weights must add up to 100.');
        }

        $production = $group->first();
        foreach ($group as $member) {
            if ($weights[$member->uuid] === 0) {
                continue;
            }
            if ($member->destination->server_id !== $production->destination->server_id) {
                throw new ApplicationSlotException("{$member->name} runs on another server. A slot on another server cannot get traffic yet.");
            }
            if ($reason = SwapApplicationSlot::unsupportedReason($member)) {
                throw new ApplicationSlotException("{$member->name}: {$reason}");
            }
            if ($member->get_last_successful_deployment(['id']) === null) {
                throw new ApplicationSlotException("{$member->name} has no successful deployment. Deploy it before it gets traffic.");
            }
        }
    }
}
