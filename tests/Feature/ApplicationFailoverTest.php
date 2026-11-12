<?php

use App\Actions\Application\SwapApplicationPrimary;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\AvailabilityIncidentReason;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\FailoverServerApplicationsJob;
use App\Jobs\RefreshStandbyApplicationsJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\SyncGithubDeploymentJob;
use App\Livewire\Project\Shared\Destination;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\AuditEvent;
use App\Models\DnsProviderZone;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Application\Failover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    // A notification is only sent through an enabled channel.
    $this->team->emailNotificationSettings()->update(['use_instance_email_settings' => true]);
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->primary = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => '203.0.113.10']);
    $this->standby = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => '203.0.113.20']);
    $this->primary->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->standby->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->primaryNetwork = StandaloneDocker::query()->where('server_id', $this->primary->id)->firstOrFail();
    $this->standbyNetwork = StandaloneDocker::query()->where('server_id', $this->standby->id)->firstOrFail();

    $this->environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->primaryNetwork->id,
        'destination_type' => StandaloneDocker::class,
        'fqdn' => 'https://app.example.com',
        'docker_registry_image_name' => 'registry.example.com/app',
        'status' => 'running:healthy',
    ]);
    $this->application->additional_networks()->attach($this->standbyNetwork->id, ['server_id' => $this->standby->id, 'status' => 'running:unhealthy']);
});

afterEach(function () {
    Server::flushIdentityMap();
});

/**
 * Marks the primary server down. The live SSH recheck of the failover job fails, unless $answersRecheck is set.
 */
function takeFailoverPrimaryDown(object $test, bool $answersRecheck = false): void
{
    Queue::fake([FailoverServerApplicationsJob::class, ServerConnectionCheckJob::class]);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Sleep::fake();
    Process::fake(['*' => $answersRecheck
        ? Process::result(output: '{"Server":{"Version":"29.4.3"}}', exitCode: 0)
        : Process::result(errorOutput: 'Connection refused', exitCode: 255)]);
    $test->primary->settings->update(['is_reachable' => false, 'is_usable' => false]);
    Server::flushIdentityMap();
}

it('swaps the primary and the additional destination and keeps the status of each server', function () {
    SwapApplicationPrimary::run($this->application, $this->standbyNetwork, 'ui');

    $application = $this->application->fresh();
    expect($application->destination_id)->toBe($this->standbyNetwork->id)
        ->and($application->realStatus())->toBe('running:unhealthy')
        ->and($application->additional_networks)->toHaveCount(1)
        ->and($application->additional_networks->first()->id)->toBe($this->primaryNetwork->id)
        ->and($application->additional_networks->first()->pivot->status)->toBe('running:healthy');
    expect(AuditEvent::query()->where('event', 'ui.application.primary_swapped')->where('resource_uuid', $application->uuid)->exists())->toBeTrue();
});

it('points the managed DNS record of the application to the new primary server', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => [
            ['id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10'],
        ]]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/record-1' => Http::response(['success' => true, 'result' => ['id' => 'record-1']]),
    ]);
    $token = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare', 'token' => 'cf-secret', 'capabilities' => ['dns']]);
    $zone = DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);
    ManagedDnsRecord::factory()->create([
        'dns_provider_zone_id' => $zone->id, 'provider_record_id' => 'record-1', 'name' => 'app.example.com', 'content' => '203.0.113.10',
    ])->addReference($this->application);

    $dns = SwapApplicationPrimary::run($this->application, $this->standbyNetwork, 'ui');

    expect($dns)->toBe(['repointed' => ['app.example.com'], 'failed' => [], 'unmanaged' => []]);
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/dns_records/record-1') && $request['content'] === '203.0.113.20');
});

it('reports the domains whose DNS Coolify does not manage', function () {
    Http::preventStrayRequests();

    $dns = SwapApplicationPrimary::run($this->application, $this->standbyNetwork, 'ui');

    expect($dns['unmanaged'])->toBe(['app.example.com']);
});

it('fails over to the additional server that runs the application when the primary server is down', function () {
    Notification::fake();
    $this->application->additional_networks()->updateExistingPivot($this->standbyNetwork->id, ['status' => 'running:healthy']);
    $this->application->settings->update(['is_auto_failover_enabled' => true]);
    $withoutFailover = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->primaryNetwork->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    takeFailoverPrimaryDown($this);

    (new FailoverServerApplicationsJob($this->primary->fresh()))->handle();

    $outage = $this->application->availabilityIncidents()->sole();
    expect($this->application->fresh()->destination_id)->toBe($this->standbyNetwork->id)
        ->and($withoutFailover->fresh()->destination_id)->toBe($this->primaryNetwork->id)
        ->and($outage->reason)->toBe(AvailabilityIncidentReason::SERVER_DOWN)
        ->and($outage->started_at->equalTo($this->primary->openAvailabilityIncident()->started_at))->toBeTrue()
        ->and($outage->ended_at)->not->toBeNull();
    Notification::assertSentTo($this->team, Failover::class, fn (Failover $notification) => $notification->newServer?->is($this->standby));
});

it('does not fail over when the primary server answers the recheck', function () {
    Notification::fake();
    $this->application->settings->update(['is_auto_failover_enabled' => true]);
    takeFailoverPrimaryDown($this, answersRecheck: true);

    (new FailoverServerApplicationsJob($this->primary->fresh()))->handle();

    expect($this->application->fresh()->destination_id)->toBe($this->primaryNetwork->id);
    Notification::assertNothingSent();
});

it('notifies the team when no additional server runs the application', function () {
    Notification::fake();
    $this->application->additional_networks()->updateExistingPivot($this->standbyNetwork->id, ['status' => 'exited']);
    $this->application->settings->update(['is_auto_failover_enabled' => true]);
    takeFailoverPrimaryDown($this);

    (new FailoverServerApplicationsJob($this->primary->fresh()))->handle();

    expect($this->application->fresh()->destination_id)->toBe($this->primaryNetwork->id)
        ->and($this->application->openAvailabilityIncident()?->reason)->toBe(AvailabilityIncidentReason::SERVER_DOWN);
    Notification::assertSentTo($this->team, Failover::class, fn (Failover $notification) => $notification->newServer === null);
});

it('checks a primary server of automatic failover applications again soon after a failed check', function (bool $failover, bool $rechecked) {
    $this->application->settings->update(['is_auto_failover_enabled' => $failover]);
    Queue::fake([ServerConnectionCheckJob::class]);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Sleep::fake();
    Process::fake(['*' => Process::result(errorOutput: 'Connection refused', exitCode: 255)]);

    (new ServerConnectionCheckJob($this->primary->fresh()))->handle();

    expect($this->primary->fresh()->unreachable_count)->toBe(1);
    $rechecked
        ? Queue::assertPushed(ServerConnectionCheckJob::class, fn (ServerConnectionCheckJob $job) => $job->server->is($this->primary) && $job->delay !== null)
        : Queue::assertNotPushed(ServerConnectionCheckJob::class);
})->with([
    'with automatic failover' => [true, true],
    'without automatic failover' => [false, false],
]);

it('deploys a standby again when the application was deployed while the server was down', function () {
    Queue::fake([ApplicationDeploymentJob::class, SyncGithubDeploymentJob::class]);
    $this->application->settings->update(['is_auto_failover_enabled' => true]);
    $downSince = now()->subHour();
    ApplicationDeploymentQueue::create([
        'application_id' => (string) $this->application->id,
        'deployment_uuid' => (string) str()->uuid(),
        'pull_request_id' => 0,
        'commit' => 'abc1234',
        'server_id' => $this->primary->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);

    (new RefreshStandbyApplicationsJob($this->standby, $downSince))->handle();

    expect(ApplicationDeploymentQueue::query()
        ->where('server_id', $this->standby->id)->where('only_this_server', true)->where('commit', 'abc1234')->exists())->toBeTrue();
});

it('does not deploy a standby that missed no deployment', function () {
    Queue::fake([ApplicationDeploymentJob::class, SyncGithubDeploymentJob::class]);
    $this->application->settings->update(['is_auto_failover_enabled' => true]);

    (new RefreshStandbyApplicationsJob($this->standby, now()->subHour()))->handle();

    expect(ApplicationDeploymentQueue::query()->where('server_id', $this->standby->id)->exists())->toBeFalse();
});

it('turns automatic failover on from the servers page', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(Destination::class, ['resource' => $this->application])
        ->set('isAutoFailoverEnabled', true)
        ->call('instantSaveAutoFailover')
        ->assertDispatched('success');

    expect($this->application->settings->fresh()->is_auto_failover_enabled)->toBeTrue();
});

it('keeps automatic failover off when the application has no registry image', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
    $this->application->update(['docker_registry_image_name' => null]);

    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->set('isAutoFailoverEnabled', true)
        ->call('instantSaveAutoFailover')
        ->assertDispatched('error')
        ->assertSet('isAutoFailoverEnabled', false);

    expect($this->application->settings->fresh()->is_auto_failover_enabled)->toBeFalse();
});

it('does not let a member turn on automatic failover', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Destination::class, ['resource' => $this->application])
        ->set('isAutoFailoverEnabled', true)
        ->call('instantSaveAutoFailover')
        ->assertDispatched('error');

    expect($this->application->settings->fresh()->is_auto_failover_enabled)->toBeFalse();
});

it('escapes the server name in the failover email', function () {
    $this->primary->name = '<script>alert(1)</script>';

    $mail = (new Failover($this->application, $this->primary, $this->standby))->toMail()->render();

    expect((string) $mail)->toContain('alert(1)')->not->toContain('<script>');
});
