<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\CreateApplicationSlot;
use App\Actions\Application\SwapApplicationSlot;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Support\ValidationPatterns;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

/**
 * The staging slot of an application, and the swap that moves the production traffic to it.
 */
class DeploymentSlots extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public Application $application;

    public ?string $slotName = null;

    public function getListeners(): array
    {
        return $this->teamChannelListeners([
            'ApplicationStatusChanged' => '$refresh',
        ]);
    }

    public function createSlot(): mixed
    {
        try {
            $this->authorize('update', $this->application);
            $this->authorize('create', [Application::class, $this->application->environment]);
            $this->authorize('placeResource', $this->application->destination->server);
            $this->validate(['slotName' => ValidationPatterns::nameRules(required: false)], ValidationPatterns::combinedMessages());

            CreateApplicationSlot::run($this->application, $this->slotName);
            $this->application->refresh();
            $this->slotName = null;
            $this->dispatch('success', 'Staging slot created. Deploy it, then swap.');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function swap(): mixed
    {
        try {
            $this->authorize('update', $this->application);
            $partner = $this->application->slotPartner();
            if ($partner !== null) {
                $this->authorize('update', $partner);
            }

            SwapApplicationSlot::run($this->application);
            $this->application->refresh();
            $this->dispatch('success', 'Swap started. The staging slot restarts with the production domains first.');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function render()
    {
        $partner = $this->application->slotPartner();
        [$production, $staging] = $this->application->slotOf ? [$partner, $this->application] : [$this->application, $partner];
        $deploymentUrls = $partner === null ? [] : ApplicationDeploymentQueue::query()
            ->whereIn('application_id', [$production->id, $staging->id])
            ->where('pull_request_id', 0)
            ->whereIn('status', [ApplicationDeploymentStatus::QUEUED->value, ApplicationDeploymentStatus::IN_PROGRESS->value])
            ->orderBy('id')
            ->pluck('deployment_url', 'application_id')
            ->all();
        $swapping = $partner !== null && Cache::has(SwapApplicationSlot::lockKey($this->application->id, $partner->id));

        return view('livewire.project.application.deployment-slots', [
            'production' => $production,
            'staging' => $staging,
            'swapping' => $swapping,
            'deploymentUrls' => $deploymentUrls,
            'unsupportedReason' => SwapApplicationSlot::unsupportedReason($this->application),
            'hasVolumes' => $this->application->persistentStorages()->exists(),
        ]);
    }
}
