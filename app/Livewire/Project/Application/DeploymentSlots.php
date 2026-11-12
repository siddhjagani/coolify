<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\CreateApplicationSlot;
use App\Actions\Application\SetSlotTrafficSplit;
use App\Actions\Application\SwapApplicationSlot;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Support\ValidationPatterns;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

/**
 * The deployment slots of an application: the slots, the swap that makes a slot production, and the traffic split.
 */
class DeploymentSlots extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public Application $application;

    public ?string $slotName = null;

    /** @var array<string, int|string|null> Application UUID => percent of the production traffic. */
    public array $weights = [];

    public function getListeners(): array
    {
        return $this->teamChannelListeners([
            'ApplicationStatusChanged' => '$refresh',
        ]);
    }

    public function mount(): void
    {
        $this->loadWeights();
    }

    public function createSlot(): mixed
    {
        try {
            $production = $this->application->slotOf ?? $this->application;
            $this->authorize('update', $production);
            $this->authorize('create', [Application::class, $production->environment]);
            $this->authorize('placeResource', $production->destination->server);
            $this->validate(['slotName' => ValidationPatterns::nameRules(required: false)], ValidationPatterns::combinedMessages());

            CreateApplicationSlot::run($this->application, $this->slotName);
            $this->application->refresh();
            $this->slotName = null;
            $this->loadWeights();
            $this->dispatch('success', 'Slot created. Deploy it, then swap it or give it traffic.');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function swap(string $slotUuid): mixed
    {
        try {
            $group = $this->application->slotGroup();
            $slot = $group->skip(1)->firstWhere('uuid', $slotUuid);
            if ($slot === null) {
                throw new \RuntimeException('The slot was not found.');
            }
            $this->authorize('update', $group->first());
            $this->authorize('update', $slot);

            SwapApplicationSlot::run($this->application, 'ui', $slot);
            $this->application->refresh();
            $this->dispatch('success', "Swap started. {$slot->name} restarts with the production domains first.");
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function saveTrafficSplit(): mixed
    {
        try {
            $group = $this->authorizeGroup();
            $this->validate(['weights.*' => 'required|integer|min:0|max:100']);

            $weights = $group->mapWithKeys(fn (Application $member) => [$member->uuid => (int) ($this->weights[$member->uuid] ?? 0)])->all();
            SetSlotTrafficSplit::run($this->application, $weights);
            $this->application->refresh();
            $this->dispatch('success', 'Traffic split saved.');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function turnOffTrafficSplit(): mixed
    {
        try {
            $this->authorizeGroup();

            SetSlotTrafficSplit::run($this->application, null);
            $this->application->refresh();
            $this->loadWeights();
            $this->dispatch('success', 'Traffic split turned off. Production gets all the traffic.');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function render()
    {
        $group = $this->application->slotGroup();
        $production = $group->first() ?? $this->application;
        $deploymentUrls = $group->isEmpty() ? [] : ApplicationDeploymentQueue::query()
            ->whereIn('application_id', $group->pluck('id'))
            ->where('pull_request_id', 0)
            ->whereIn('status', [ApplicationDeploymentStatus::QUEUED->value, ApplicationDeploymentStatus::IN_PROGRESS->value])
            ->orderBy('id')
            ->pluck('deployment_url', 'application_id')
            ->all();

        return view('livewire.project.application.deployment-slots', [
            'group' => $group,
            'production' => $production,
            'splitOn' => $group->isNotEmpty() && $production->settings->slot_traffic_weight !== null,
            'swapping' => $group->isNotEmpty() && Cache::has(SwapApplicationSlot::lockKey($production->id)),
            'deploymentUrls' => $deploymentUrls,
            'unsupportedReason' => SwapApplicationSlot::unsupportedReason($this->application),
            'hasVolumes' => $group->contains(fn (Application $member) => $member->persistentStorages()->exists()),
        ]);
    }

    /**
     * @return Collection<int, Application>
     */
    private function authorizeGroup()
    {
        $group = $this->application->slotGroup();
        foreach ($group as $member) {
            $this->authorize('update', $member);
        }

        return $group;
    }

    private function loadWeights(): void
    {
        $group = $this->application->slotGroup();
        $splitOn = $group->isNotEmpty() && $group->first()->settings->slot_traffic_weight !== null;
        $this->weights = $group->mapWithKeys(fn (Application $member, int $index) => [
            $member->uuid => $splitOn ? (int) $member->settings->slot_traffic_weight : ($index === 0 ? 100 : 0),
        ])->all();
    }
}
