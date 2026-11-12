<?php

namespace App\Traits;

use App\Enums\AvailabilityIncidentReason;
use App\Models\AvailabilityIncident;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Outage history and uptime percentage of a server or an application.
 * An incident is a period in which the model was down; an open incident is still going on.
 */
trait HasAvailability
{
    public function availabilityIncidents(): HasMany
    {
        return $this->hasMany(AvailabilityIncident::class);
    }

    public function openAvailabilityIncident(): ?AvailabilityIncident
    {
        return $this->availabilityIncidents()->whereNull('ended_at')->latest('started_at')->first();
    }

    /**
     * Opens an incident unless one is open already. Returns the new incident, or null when one was open.
     * $startedAt backdates it, for an outage that was found later than it began.
     */
    public function startAvailabilityIncident(AvailabilityIncidentReason $reason, ?CarbonInterface $startedAt = null): ?AvailabilityIncident
    {
        if ($this->availabilityIncidents()->whereNull('ended_at')->exists()) {
            return null;
        }

        return $this->availabilityIncidents()->create(['reason' => $reason, 'started_at' => $startedAt ?? now()]);
    }

    /**
     * Ends the open incident. Returns it, or null when none was open.
     */
    public function endAvailabilityIncident(): ?AvailabilityIncident
    {
        $incident = $this->openAvailabilityIncident();
        $incident?->update(['ended_at' => now()]);

        return $incident;
    }

    /**
     * Percentage of the time since $since that the model was up, from 0 to 100.
     * Time before the model was created does not count.
     */
    public function availabilityPercentage(CarbonInterface $since): float
    {
        $now = now()->getTimestamp();
        $start = max($since->getTimestamp(), $this->created_at?->getTimestamp() ?? $since->getTimestamp());
        $window = $now - $start;
        if ($window <= 0) {
            return 100.0;
        }

        $downSeconds = $this->incidentsOverlapping($since)
            ->get(['started_at', 'ended_at'])
            ->sum(fn (AvailabilityIncident $incident): int => max(
                0,
                min($incident->ended_at?->getTimestamp() ?? $now, $now) - max($incident->started_at->getTimestamp(), $start),
            ));

        return round(max(0, 100 * (1 - $downSeconds / $window)), 3);
    }

    /**
     * @return array{'24h': float, '7d': float, '30d': float} uptime percentage by period
     */
    public function availabilitySummary(): array
    {
        return [
            '24h' => $this->availabilityPercentage(now()->subDay()),
            '7d' => $this->availabilityPercentage(now()->subDays(7)),
            '30d' => $this->availabilityPercentage(now()->subDays(30)),
        ];
    }

    /**
     * Uptime and the outages of the last 30 days, as the API returns them.
     *
     * @return array{uptime_percentage: array{'24h': float, '7d': float, '30d': float}, incidents: list<array{reason: string, started_at: string, ended_at: ?string, duration_seconds: int}>}
     */
    public function availabilityReport(): array
    {
        return [
            'uptime_percentage' => $this->availabilitySummary(),
            'incidents' => $this->incidentsOverlapping(now()->subDays(30))->limit(50)->get()
                ->map(fn (AvailabilityIncident $incident): array => [
                    'reason' => $incident->reason->value,
                    'started_at' => $incident->started_at->toIso8601String(),
                    'ended_at' => $incident->ended_at?->toIso8601String(),
                    'duration_seconds' => $incident->durationInSeconds(),
                ])->values()->all(),
        ];
    }

    /**
     * Incidents that were open at some time after $since, newest first.
     */
    public function incidentsOverlapping(CarbonInterface $since): HasMany
    {
        return $this->availabilityIncidents()
            ->where(fn (Builder $query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $since))
            ->latest('started_at');
    }
}
