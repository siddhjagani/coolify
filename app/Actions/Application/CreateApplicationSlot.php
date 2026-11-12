<?php

namespace App\Actions\Application;

use App\Exceptions\ApplicationSlotException;
use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Creates the staging slot of an application: a clone on the same server, with a generated domain, its own empty
 * volumes, no network aliases, and no scheduled tasks, so it gets no production traffic until a swap. It is not
 * deployed. Callers authorize as for a clone.
 */
class CreateApplicationSlot
{
    use AsAction;

    /**
     * @throws ApplicationSlotException when the application cannot have a slot
     */
    public function handle(Application $production, ?string $name = null, string $source = 'ui'): Application
    {
        if ($production->slotPartner() !== null) {
            throw new ApplicationSlotException('The application already has a deployment slot.', 409);
        }
        if ($reason = SwapApplicationSlot::unsupportedReason($production)) {
            throw new ApplicationSlotException($reason);
        }
        if ($production->destination->server->proxyType() === 'NONE') {
            throw new ApplicationSlotException('The server has no proxy, so a deployment slot cannot get its own domain.');
        }

        $slot = clone_application($production, $production->destination, [
            'name' => filled($name) ? $name : "{$production->name}-staging",
        ]);
        $slot->forceFill(['slot_of_id' => $production->id, 'custom_network_aliases' => null])->save();
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
