<?php

namespace App\Actions\Application;

use App\Exceptions\ApplicationSlotException;
use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Creates a deployment slot of an application: a clone of production on the same server, with a generated domain, its
 * own empty volumes, no network aliases, no scheduled tasks, and no traffic weight, so it gets no production traffic
 * until a swap or a traffic split. It is not deployed. Callers authorize as for a clone.
 */
class CreateApplicationSlot
{
    use AsAction;

    /**
     * @throws ApplicationSlotException when the application cannot have a slot
     */
    public function handle(Application $application, ?string $name = null, string $source = 'ui'): Application
    {
        // A slot of a slot belongs to the same group.
        $production = $application->slotOf ?? $application;
        if ($reason = SwapApplicationSlot::unsupportedReason($production)) {
            throw new ApplicationSlotException($reason);
        }
        if ($production->destination->server->proxyType() === 'NONE') {
            throw new ApplicationSlotException('The server has no proxy, so a deployment slot cannot get its own domain.');
        }

        $slotCount = $production->slots()->count();
        $slot = clone_application($production, $production->destination, [
            'name' => filled($name) ? $name : ($slotCount === 0 ? "{$production->name}-staging" : "{$production->name}-slot-".($slotCount + 1)),
        ]);
        $slot->forceFill(['slot_of_id' => $production->id, 'custom_network_aliases' => null])->save();
        $slot->settings->update(['slot_traffic_weight' => null]);
        $slot->scheduled_tasks()->delete();

        auditLog("{$source}.application.slot_created", [
            'team_id' => $production->team()?->id,
            'application_uuid' => $production->uuid,
            'application_name' => $production->name,
            'slot_uuid' => $slot->uuid,
            'slot_name' => $slot->name,
        ]);

        return $slot;
    }
}
