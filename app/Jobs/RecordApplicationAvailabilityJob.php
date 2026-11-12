<?php

namespace App\Jobs;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Records the outages of the applications with automatic failover (see Application::recordAvailability()).
 * Reads only the database: the status checks keep the statuses current.
 */
class RecordApplicationAvailabilityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    public function handle(): void
    {
        Application::query()
            ->whereRelation('settings', 'is_auto_failover_enabled', true)
            ->with('destination.server.settings')
            ->chunkById(100, fn (Collection $applications) => $applications->each->recordAvailability());
    }
}
