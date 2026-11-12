@php
    $busy = $swapping || $deploymentUrls !== [];
@endphp
{{-- Statuses and deployments started elsewhere show up within 10 seconds, and a running swap within 5. --}}
<div class="flex flex-col gap-6" @if ($busy) wire:poll.5s @elseif ($group->isNotEmpty()) wire:poll.10s @endif>
    <x-application.settings-section title="Deployment slots"
        helper="Slots run next to production on the same server, each with its own domain. Deploy and test a new version in a slot, then make it production (a swap without downtime), or give each slot a share of the production traffic."
        flush>
        <div class="flex flex-col gap-4 px-4 py-3.5">
            @if ($unsupportedReason && $group->isEmpty())
                <x-callout type="warning" title="Deployment slots are not available">
                    {{ $unsupportedReason }}
                </x-callout>
            @else
                @if ($group->isEmpty())
                    <p class="text-sm text-neutral-600 dark:text-fg-dim">
                        A slot is a copy of this application with a generated domain, its own empty volumes, and no
                        network aliases or scheduled tasks. It is not deployed until you deploy it.
                    </p>
                @endif

                @if ($swapping)
                    @php
                        // During a swap, the promoted slot already has the production domains.
                        $swappingSlot = $group->skip(1)->first(fn ($member) => $member->fqdn === $production->fqdn);
                    @endphp
                    <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-600 dark:text-fg-dim">
                        @if ($swappingSlot && isset($deploymentUrls[$swappingSlot->id]))
                            <x-deploying-indicator label="Swapping" :href="$deploymentUrls[$swappingSlot->id]" />
                        @else
                            <x-status-badge status="Swapping…" type="warning" spinning />
                        @endif
                        <span>The slot restarts with the production domains, then {{ $production->name }} restarts with
                            the slot's domains. Production keeps serving meanwhile.</span>
                    </div>
                @endif

                @foreach ($group as $member)
                    @php
                        $isProduction = $loop->first;
                    @endphp
                    <div class="flex flex-col gap-1.5 border-b border-neutral-200 pb-3 last:border-b-0 dark:border-white/[0.07]"
                        wire:key="deployment-slot-{{ $member->uuid }}">
                        <span class="text-[11px] font-medium uppercase tracking-wide text-neutral-400 dark:text-fg-faint">
                            {{ $isProduction ? 'Production slot' : 'Slot' }}
                        </span>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($member->is($application))
                                <span class="font-semibold text-black dark:text-fg">{{ $member->name }}</span>
                            @else
                                <a class="font-semibold underline" href="{{ $member->link() }}" {{ wireNavigate() }}>{{ $member->name }}</a>
                            @endif
                            <x-status-summary :status="$member->status" />
                            @if ($isProduction)
                                <x-status-badge status="Live" type="success" title="This application serves the production domains." />
                            @endif
                            @if ($splitOn && (int) $member->settings->slot_traffic_weight > 0)
                                <x-status-badge :status="$member->settings->slot_traffic_weight . '% of traffic'" type="neutral" />
                            @endif
                            @if (isset($deploymentUrls[$member->id]) && ! $swapping)
                                <x-deploying-indicator :href="$deploymentUrls[$member->id]" />
                            @endif
                            @if (! $isProduction)
                                @can('update', $member)
                                    <div class="ml-auto">
                                        <x-modal-confirmation title="Make {{ $member->name }} production?" buttonTitle="Make production"
                                            submitAction="swap('{{ $member->uuid }}')"
                                            warningMessage="Both applications restart. To go back, swap again."
                                            :disabled="$busy" disabledTooltip="Wait until the running swap or deployment ends."
                                            :actions="[
                                                $member->name . ' restarts with the production domains, proxy settings, basic auth, network aliases, automatic deployment, and traffic share. Production keeps serving meanwhile.',
                                                'When it runs, ' . $production->name . ' restarts with the domains of ' . $member->name . '.',
                                                'The two applications swap their names and scheduled tasks. Code, environment variables, volumes, and UUIDs stay with each application.',
                                                'If the restart fails, nothing changes for production.',
                                            ]"
                                            :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Make production" />
                                    </div>
                                @endcan
                            @endif
                        </div>
                        <p class="break-all text-xs text-neutral-500 dark:text-fg-dim">{{ $member->fqdn ?: 'No domains' }}</p>
                    </div>
                @endforeach

                @if ($hasVolumes)
                    <x-callout type="warning" title="Volumes do not move">
                        Each application keeps its own volumes. Data in volumes stays with the application when you swap.
                    </x-callout>
                @endif

                @can('update', $application)
                    @unless ($unsupportedReason)
                        <form wire:submit="createSlot" class="flex max-w-xl flex-col gap-3 sm:flex-row sm:items-end">
                            <x-forms.input id="slotName" label="New slot name"
                                placeholder="{{ $production->name }}-{{ $group->isEmpty() ? 'staging' : 'slot-' . $group->count() }}"
                                helper="Optional." />
                            <x-forms.button type="submit">Add slot</x-forms.button>
                        </form>
                    @endunless
                @endcan
            @endif
        </div>
    </x-application.settings-section>

    @if ($group->count() > 1)
        <x-application.settings-section title="Traffic split"
            helper="Share the traffic of the production domains between the slots, in percent. A cookie keeps each visitor on one slot, so the split is by visitors. A slot that is stopped gets no traffic: production then serves all of it."
            flush>
            <form wire:submit="saveTrafficSplit" class="flex flex-col gap-3 px-4 py-3.5"
                x-data="{ weights: $wire.entangle('weights') }">
                @foreach ($group as $member)
                    <div class="flex max-w-md items-end gap-3" wire:key="slot-weight-{{ $member->uuid }}">
                        <div class="flex-1">
                            <x-forms.input type="number" min="0" max="100" id="weights.{{ $member->uuid }}"
                                label="{{ $member->name }}{{ $loop->first ? ' (production)' : '' }}"
                                canGate="update" :canResource="$member" />
                        </div>
                        <span class="pb-2 text-sm text-neutral-500 dark:text-fg-dim">%</span>
                    </div>
                @endforeach
                <p class="text-sm" :class="Object.values(weights).reduce((sum, weight) => sum + Number(weight || 0), 0) === 100 ? 'text-neutral-500 dark:text-fg-dim' : 'text-error'">
                    Total: <span x-text="Object.values(weights).reduce((sum, weight) => sum + Number(weight || 0), 0)"></span>% (must be 100%)
                </p>
                @can('update', $application)
                    <div class="flex gap-2">
                        <x-forms.button type="submit" :disabled="$swapping">Save traffic split</x-forms.button>
                        @if ($splitOn)
                            <x-forms.button type="button" wire:click="turnOffTrafficSplit" :disabled="$swapping">Turn off</x-forms.button>
                        @endif
                    </div>
                @endcan
            </form>
        </x-application.settings-section>
    @endif
</div>
