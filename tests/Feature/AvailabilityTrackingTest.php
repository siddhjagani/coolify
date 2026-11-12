<?php

use App\Enums\AvailabilityIncidentReason;
use App\Jobs\FailoverServerApplicationsJob;
use App\Jobs\NotifyResourceStoppedJob;
use App\Jobs\RefreshStandbyApplicationsJob;
use App\Models\Application;
use App\Models\AvailabilityIncident;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->primary = Server::factory()->create(['team_id' => $this->team->id]);
    $this->standby = Server::factory()->create(['team_id' => $this->team->id]);
    $this->primary->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->standby->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => StandaloneDocker::query()->where('server_id', $this->primary->id)->value('id'),
        'destination_type' => StandaloneDocker::class,
        'status' => 'running:healthy',
    ]);
    $this->application->additional_networks()->attach(
        StandaloneDocker::query()->where('server_id', $this->standby->id)->value('id'),
        ['server_id' => $this->standby->id, 'status' => 'running:healthy'],
    );
    $this->application->settings->update(['is_auto_failover_enabled' => true]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

it('counts only the downtime inside the period', function () {
    $this->freezeTime();
    $this->primary->forceFill(['created_at' => now()->subDays(40)])->save();
    $incident = fn (int $startedMinutesAgo, ?int $endedMinutesAgo) => AvailabilityIncident::factory()->create([
        'server_id' => $this->primary->id,
        'started_at' => now()->subMinutes($startedMinutesAgo),
        'ended_at' => $endedMinutesAgo === null ? null : now()->subMinutes($endedMinutesAgo),
    ]);
    $incident(40 * 60, 39 * 60);   // before the period
    $incident(26 * 60, 23 * 60);   // 60 minutes inside the period
    $incident(60, 30);             // 30 minutes
    $incident(10, null);           // still open: 10 minutes

    // 100 of 1440 minutes down.
    expect($this->primary->availabilityPercentage(now()->subDay()))->toBe(93.056);
});

it('does not count the time before the server was created', function () {
    $this->freezeTime();
    $this->primary->forceFill(['created_at' => now()->subHours(12)])->save();
    AvailabilityIncident::factory()->create([
        'server_id' => $this->primary->id,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHour(),
    ]);

    expect($this->primary->availabilityPercentage(now()->subDays(30)))->toBe(91.667);
});

it('shows the outages of a server on its availability page', function () {
    $this->withoutVite();
    $this->team->update(['show_boarding' => false]);
    $owner = User::factory()->create();
    $this->team->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);
    AvailabilityIncident::factory()->create([
        'server_id' => $this->primary->id,
        'reason' => AvailabilityIncidentReason::DOCKER_UNAVAILABLE,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHour(),
    ]);

    $this->get(route('server.availability', ['server_uuid' => $this->primary->uuid]))
        ->assertOk()
        ->assertSee('Docker unavailable');
});

it('returns 404 for the availability page of a server of another team', function () {
    $this->withoutVite();
    $otherTeam = Team::factory()->create(['show_boarding' => false]);
    $outsider = User::factory()->create();
    $otherTeam->members()->attach($outsider->id, ['role' => 'owner']);
    $this->actingAs($outsider);
    session(['currentTeam' => $otherTeam]);

    $this->get(route('server.availability', ['server_uuid' => $this->primary->uuid]))->assertNotFound();
});

it('opens an outage and starts the failover when a server with failover applications goes down', function () {
    Queue::fake([FailoverServerApplicationsJob::class]);

    $this->primary->settings->update(['is_reachable' => false, 'is_usable' => false]);

    expect($this->primary->openAvailabilityIncident()?->reason)->toBe(AvailabilityIncidentReason::UNREACHABLE);
    Queue::assertPushed(FailoverServerApplicationsJob::class, fn (FailoverServerApplicationsJob $job) => $job->server->is($this->primary));
});

it('starts a server outage at the first failed connection check', function () {
    Queue::fake([FailoverServerApplicationsJob::class]);
    $this->freezeTime();
    $firstFailure = now()->subSeconds(30);
    $this->travelTo($firstFailure, fn () => $this->primary->rememberFailedCheck());
    $this->primary->rememberFailedCheck();

    $this->primary->settings->update(['is_reachable' => false, 'is_usable' => false]);

    expect($this->primary->openAvailabilityIncident()->started_at->getTimestamp())->toBe($firstFailure->getTimestamp());
});

it('records docker as unavailable when the server answers but docker does not', function () {
    Queue::fake([FailoverServerApplicationsJob::class]);

    $this->primary->settings->update(['is_usable' => false]);

    expect($this->primary->openAvailabilityIncident()?->reason)->toBe(AvailabilityIncidentReason::DOCKER_UNAVAILABLE);
});

it('does not start a failover when no application on the server has automatic failover', function () {
    Queue::fake([FailoverServerApplicationsJob::class]);

    $this->standby->settings->update(['is_reachable' => false, 'is_usable' => false]);

    expect($this->standby->openAvailabilityIncident())->not->toBeNull();
    Queue::assertNotPushed(FailoverServerApplicationsJob::class);
});

it('ends the outage and refreshes the standby applications when the server is back', function () {
    Queue::fake([RefreshStandbyApplicationsJob::class]);
    $this->standby->settings->update(['is_reachable' => false, 'is_usable' => false]);

    $this->standby->settings->update(['is_reachable' => true, 'is_usable' => true]);

    expect($this->standby->openAvailabilityIncident())->toBeNull()
        ->and($this->standby->availabilityIncidents()->whereNotNull('ended_at')->count())->toBe(1);
    Queue::assertPushed(RefreshStandbyApplicationsJob::class, fn (RefreshStandbyApplicationsJob $job) => $job->server->is($this->standby));
});

it('does not record an outage for a disabled server and ends the open one', function () {
    Queue::fake([FailoverServerApplicationsJob::class]);
    $this->primary->settings->update(['is_reachable' => false, 'is_usable' => false]);

    $this->primary->settings->update(['force_disabled' => true]);
    $this->primary->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->primary->settings->update(['is_reachable' => false, 'is_usable' => false]);

    expect($this->primary->openAvailabilityIncident())->toBeNull()
        ->and($this->primary->availabilityIncidents()->count())->toBe(1);
});

it('does not record an outage for a server that was not up', function () {
    Queue::fake([FailoverServerApplicationsJob::class]);
    $this->primary->settings->update(['is_usable' => false]);
    $this->primary->availabilityIncidents()->delete();

    $this->primary->settings->update(['is_reachable' => false]);

    expect($this->primary->availabilityIncidents()->count())->toBe(0);
});

it('keeps the primary status of an application when its additional server goes down', function () {
    $this->standby->markResourcesAsExited();

    $application = $this->application->fresh();
    expect($application->realStatus())->toBe('running:healthy')
        ->and($application->additional_servers()->first()->pivot->status)->toBe('exited');
});

it('opens an application outage when the primary server stops running it and ends it when it runs again', function () {
    $this->application->recordAvailability();

    $this->application->update(['status' => 'exited']);
    $this->application->fresh()->recordAvailability();
    $incident = $this->application->openAvailabilityIncident();

    $this->application->update(['status' => 'running:healthy']);
    $this->application->fresh()->recordAvailability();

    expect($incident?->reason)->toBe(AvailabilityIncidentReason::NOT_RUNNING)
        ->and($this->application->openAvailabilityIncident())->toBeNull();
});

it('records an application outage as a server outage when the primary server is down', function () {
    Queue::fake([FailoverServerApplicationsJob::class]);
    $this->application->recordAvailability();

    $this->primary->settings->update(['is_reachable' => false, 'is_usable' => false]);
    Server::flushIdentityMap();
    Application::query()->with('destination.server.settings')->find($this->application->id)->recordAvailability();

    expect($this->application->openAvailabilityIncident()?->reason)->toBe(AvailabilityIncidentReason::SERVER_DOWN);
});

it('does not open an application outage for a stop by Coolify', function () {
    $this->application->recordAvailability();

    NotifyResourceStoppedJob::expectStop($this->application);
    $this->application->update(['status' => 'exited']);
    $this->application->fresh()->recordAvailability();

    expect($this->application->availabilityIncidents()->count())->toBe(0);
});

it('does not open an application outage on the first check', function () {
    $this->application->update(['status' => 'exited']);

    $this->application->fresh()->recordAvailability();

    expect($this->application->availabilityIncidents()->count())->toBe(0);
});

it('ends the application outage when automatic failover is turned off', function () {
    $this->application->recordAvailability();
    $this->application->update(['status' => 'exited']);
    $this->application->fresh()->recordAvailability();

    $this->application->settings->update(['is_auto_failover_enabled' => false]);

    expect($this->application->availabilityIncidents()->count())->toBe(1)
        ->and($this->application->openAvailabilityIncident())->toBeNull();
});
