@props([
    'model', // a model with the HasAvailability trait
    'server' => null, // shows the times in this server's timezone
    'emptyDescription' => 'Coolify records an outage when it marks the server as down and ends it when the server is back.',
])

@php
    $periods = $model->availabilitySummary();
    $periodLabels = ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'];
    $incidents = $model->incidentsOverlapping(now()->subDays(30))->limit(10)->get();
    // Floor, not round: 99.996% with real downtime must not show as 100%.
    $formatPercentage = fn (float $percentage): string => $percentage >= 100 ? '100' : number_format(floor($percentage * 100) / 100, 2);
@endphp

<div class="flex flex-col gap-4">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ($periods as $period => $percentage)
            <div class="rounded-lg px-4 py-3 ring-1 ring-neutral-200 dark:ring-white/[0.08]">
                <p class="text-[12px] text-neutral-500 dark:text-fg-dim">Last {{ $periodLabels[$period] }}</p>
                <p @class([
                    'mt-1 text-xl font-semibold tabular-nums',
                    'text-black dark:text-fg' => $percentage >= 99.9,
                    'text-warning' => $percentage < 99.9 && $percentage >= 99,
                    'text-error' => $percentage < 99,
                ])>{{ $formatPercentage($percentage) }}%</p>
            </div>
        @endforeach
    </div>

    @if ($incidents->isEmpty())
        <x-empty size="sm" title="No outages in the last 30 days" :description="$emptyDescription" icon-name="check-circle" />
    @else
        <div class="overflow-hidden rounded-lg ring-1 ring-neutral-200 dark:ring-white/[0.08]">
            <div class="overflow-x-auto">
                <table class="w-full min-w-xl">
                    <thead>
                        <tr>
                            <th>Started</th>
                            <th>Duration</th>
                            <th>Reason</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($incidents as $incident)
                            <tr wire:key="availability-incident-{{ $incident->id }}">
                                <td class="tabular-nums">{{ formatDateInServerTimezone($incident->started_at, $server) }}</td>
                                <td class="tabular-nums">
                                    {{ \Carbon\CarbonInterval::seconds($incident->durationInSeconds())->cascade()->forHumans(['short' => true, 'parts' => 2]) }}
                                </td>
                                <td>{{ $incident->reason->label() }}</td>
                                <td>
                                    @if ($incident->ended_at === null)
                                        <x-status-badge label="Ongoing" type="error" pulse />
                                    @else
                                        <x-status-badge label="Resolved" type="neutral" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
