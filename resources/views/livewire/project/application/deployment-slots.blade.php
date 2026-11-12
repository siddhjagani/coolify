<div class="flex flex-col gap-6" @if ($swapping) wire:poll.5s @endif>
    <x-application.settings-section title="Deployment slots"
        helper="A staging slot runs next to production on the same server, with its own domain. Deploy and test a new version there, then swap: the staging slot takes the production traffic without downtime, and the old production becomes the staging slot. Swap again to go back."
        flush>
        <div class="flex flex-col gap-4 px-4 py-3.5">
            @if ($staging === null)
                @if ($unsupportedReason)
                    <x-callout type="warning" title="Deployment slots are not available">
                        {{ $unsupportedReason }}
                    </x-callout>
                @else
                    <p class="text-sm text-neutral-600 dark:text-fg-dim">
                        The staging slot is a copy of this application with a generated domain, its own empty volumes,
                        and no network aliases or scheduled tasks. It is not deployed until you deploy it.
                    </p>
                    @can('update', $application)
                        <form wire:submit="createSlot" class="flex max-w-xl flex-col gap-3 sm:flex-row sm:items-end">
                            <x-forms.input id="slotName" label="Slot name"
                                placeholder="{{ $application->name }}-staging"
                                helper="Optional. The default is the application name with -staging." />
                            <x-forms.button type="submit">Create staging slot</x-forms.button>
                        </form>
                    @endcan
                @endif
            @else
                @if ($swapping)
                    <x-callout type="info" title="Swap in progress">
                        The staging slot restarts with the production domains. When it runs, the old production
                        application restarts with the staging domains.
                    </x-callout>
                @endif

                @foreach (['Production' => $production, 'Staging' => $staging] as $role => $slot)
                    <div class="flex flex-col gap-1 border-b border-neutral-200 pb-3 last:border-b-0 dark:border-white/[0.07]"
                        wire:key="deployment-slot-{{ $slot->uuid }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-status-badge :status="$role" :type="$role === 'Production' ? 'success' : 'neutral'" />
                            @if ($slot->is($application))
                                <span class="font-semibold text-black dark:text-fg">{{ $slot->name }}</span>
                            @else
                                <a class="font-semibold underline" href="{{ $slot->link() }}">{{ $slot->name }}</a>
                            @endif
                            <span class="text-xs text-neutral-500 dark:text-fg-dim">{{ str($slot->status)->before(':') }}</span>
                        </div>
                        <p class="break-all text-xs text-neutral-500 dark:text-fg-dim">{{ $slot->fqdn ?: 'No domains' }}</p>
                    </div>
                @endforeach

                @if ($hasVolumes)
                    <x-callout type="warning" title="Volumes do not move">
                        Each application keeps its own volumes. Data in volumes stays with the application when you swap.
                    </x-callout>
                @endif

                @can('update', $application)
                    <div>
                        <x-modal-confirmation title="Swap deployment slots?" buttonTitle="Swap"
                            submitAction="swap" :disabled="$swapping" disabledTooltip="A swap is in progress."
                            :actions="[
                                $staging->name . ' restarts with the production domains, proxy settings, basic auth, network aliases, and automatic deployment. Production keeps serving meanwhile.',
                                'When it runs, ' . $production->name . ' restarts with the staging domains.',
                                'The two applications swap their names and scheduled tasks. Code, environment variables, volumes, and UUIDs stay with each application.',
                                'If the staging restart fails, nothing changes for production.',
                            ]"
                            :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Swap" />
                    </div>
                @endcan
            @endif
        </div>
    </x-application.settings-section>
</div>
