<?php

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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->primary = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '203.0.113.10']);
    $this->standby = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '203.0.113.20']);
    $this->primary->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->standby->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->primaryNetwork = StandaloneDocker::query()->where('server_id', $this->primary->id)->firstOrFail();
    $this->standbyNetwork = StandaloneDocker::query()->where('server_id', $this->standby->id)->firstOrFail();

    $this->application = Application::factory()->create([
        'environment_id' => Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id])->id,
        'destination_id' => $this->primaryNetwork->id,
        'destination_type' => StandaloneDocker::class,
        'docker_registry_image_name' => 'registry.example.com/app',
        'status' => 'running:healthy',
    ]);
    $this->application->additional_networks()->attach($this->standbyNetwork->id, ['server_id' => $this->standby->id, 'status' => 'running:healthy']);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function availabilityApiToken(User $user, Team $team, array $abilities = ['*']): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'availability-api-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

it('returns the uptime and the outages of a server', function () {
    AvailabilityIncident::factory()->open()->create(['server_id' => $this->primary->id, 'started_at' => now()->subMinutes(5)]);

    $this->withToken(availabilityApiToken($this->owner, $this->team))
        ->getJson("/api/v1/servers/{$this->primary->uuid}/availability")
        ->assertOk()
        ->assertJsonStructure(['uptime_percentage' => ['24h', '7d', '30d'], 'incidents'])
        ->assertJsonPath('incidents.0.reason', 'unreachable')
        ->assertJsonPath('incidents.0.ended_at', null);
});

it('returns 404 for a server of another team', function () {
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->withToken(availabilityApiToken($this->owner, $otherTeam))
        ->getJson("/api/v1/servers/{$this->primary->uuid}/availability")
        ->assertNotFound();
});

it('returns 401 without a token', function (string $method, string $path) {
    $path = str_replace(['{server}', '{application}', '{destination}'], [$this->primary->uuid, $this->application->uuid, $this->standbyNetwork->uuid], $path);

    $this->json($method, $path)->assertUnauthorized();
})->with([
    'server availability' => ['GET', '/api/v1/servers/{server}/availability'],
    'application availability' => ['GET', '/api/v1/applications/{application}/availability'],
    'automatic failover' => ['PATCH', '/api/v1/applications/{application}/availability'],
    'swap' => ['POST', '/api/v1/applications/{application}/destinations/{destination}/swap'],
]);

it('turns automatic failover on', function () {
    $this->withToken(availabilityApiToken($this->owner, $this->team))
        ->patchJson("/api/v1/applications/{$this->application->uuid}/availability", ['is_auto_failover_enabled' => true])
        ->assertOk()
        ->assertJsonPath('is_auto_failover_enabled', true)
        ->assertJsonPath('primary_server_uuid', $this->primary->uuid);

    expect($this->application->settings->fresh()->is_auto_failover_enabled)->toBeTrue();
});

it('returns 422 with the reason when the application cannot fail over', function () {
    $this->application->update(['docker_registry_image_name' => null]);

    $this->withToken(availabilityApiToken($this->owner, $this->team))
        ->patchJson("/api/v1/applications/{$this->application->uuid}/availability", ['is_auto_failover_enabled' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.is_auto_failover_enabled.0', 'Set a Docker registry image in the General tab first, so that every server runs the same image.');

    expect($this->application->settings->fresh()->is_auto_failover_enabled)->toBeFalse();
});

it('returns 422 for a field that is not allowed', function () {
    $this->withToken(availabilityApiToken($this->owner, $this->team))
        ->patchJson("/api/v1/applications/{$this->application->uuid}/availability", ['is_auto_failover_enabled' => true, 'status' => 'running'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'This field is not allowed.');
});

it('returns 403 when a member changes automatic failover', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    $this->withToken(availabilityApiToken($member, $this->team, ['read']))
        ->patchJson("/api/v1/applications/{$this->application->uuid}/availability", ['is_auto_failover_enabled' => true])
        ->assertForbidden();

    expect($this->application->settings->fresh()->is_auto_failover_enabled)->toBeFalse();
});

it('swaps the primary destination of an application', function () {
    Http::preventStrayRequests();

    $this->withToken(availabilityApiToken($this->owner, $this->team))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$this->standbyNetwork->uuid}/swap")
        ->assertOk()
        ->assertJsonPath('dns_unmanaged', []);

    expect($this->application->fresh()->destination_id)->toBe($this->standbyNetwork->id);
});

it('returns 404 when the destination is not an additional destination of the application', function () {
    $this->withToken(availabilityApiToken($this->owner, $this->team))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$this->primaryNetwork->uuid}/swap")
        ->assertNotFound();

    expect($this->application->fresh()->destination_id)->toBe($this->primaryNetwork->id);
});

it('returns 422 when the server of the destination is down', function () {
    $this->standby->settings->update(['is_reachable' => false, 'is_usable' => false]);
    Server::flushIdentityMap();

    $this->withToken(availabilityApiToken($this->owner, $this->team))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$this->standbyNetwork->uuid}/swap")
        ->assertUnprocessable();

    expect($this->application->fresh()->destination_id)->toBe($this->primaryNetwork->id);
});
