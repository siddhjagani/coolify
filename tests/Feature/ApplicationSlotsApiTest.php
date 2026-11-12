<?php

use App\Enums\ProxyTypes;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\FinishApplicationSlotSwapJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class, FinishApplicationSlotSwapJob::class]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('slots', ['write', 'deploy'])->plainTextToken;

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'running'],
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->application = Application::factory()->create([
        'name' => 'shop',
        'fqdn' => 'https://shop.example.com',
        'status' => 'running:healthy',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function slotsApiHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'];
}

function slotsApiRunningPair(Application $application): Application
{
    $slot = $application->fresh()->slots->first();
    $slot->update(['status' => 'running:healthy']);
    foreach ([$application, $slot] as $resource) {
        ApplicationDeploymentQueue::create([
            'application_id' => $resource->id,
            'deployment_uuid' => new_public_id(),
            'server_id' => $resource->destination->server_id,
            'pull_request_id' => 0,
            'status' => 'finished',
            'commit' => 'cccc000000000000000000000000000000000000',
        ]);
    }

    return $slot;
}

test('the API creates a slot and swaps it', function () {
    $create = $this->withHeaders(slotsApiHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots", ['name' => 'shop-green']);

    $create->assertCreated()->assertJsonPath('message', 'Deployment slot created.');
    $slot = Application::query()->where('uuid', $create->json('uuid'))->firstOrFail();
    expect($slot->name)->toBe('shop-green')
        ->and($slot->slot_of_id)->toBe($this->application->id);

    slotsApiRunningPair($this->application);
    $swap = $this->withHeaders(slotsApiHeaders($this->token))
        ->postJson("/api/v1/applications/{$slot->uuid}/slots/swap");

    $swap->assertStatus(202)->assertJsonPath('message', 'Swap started.');
    expect(ApplicationDeploymentQueue::query()->where('deployment_uuid', $swap->json('deployment_uuid'))->value('application_id'))->toBe((string) $slot->id)
        ->and($slot->fresh()->fqdn)->toBe('https://shop.example.com');
    Bus::assertDispatched(FinishApplicationSlotSwapJob::class);
});

test('the API refuses a swap without a slot, and asks which slot when there are several', function () {
    $this->withHeaders(slotsApiHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots/swap")
        ->assertNotFound()
        ->assertJsonPath('message', 'The application has no deployment slot.');

    $this->withHeaders(slotsApiHeaders($this->token))->postJson("/api/v1/applications/{$this->application->uuid}/slots")->assertCreated();
    $this->withHeaders(slotsApiHeaders($this->token))->postJson("/api/v1/applications/{$this->application->uuid}/slots")->assertCreated();
    $this->withHeaders(slotsApiHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots/swap")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The application has more than one slot. Choose the slot to make production.');
    $this->withHeaders(slotsApiHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots/swap", ['slot_uuid' => $this->application->uuid])
        ->assertNotFound();
});

test('the API returns why a swap is not possible', function () {
    $this->withHeaders(slotsApiHeaders($this->token))->postJson("/api/v1/applications/{$this->application->uuid}/slots")->assertCreated();

    $this->withHeaders(slotsApiHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots/swap")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Start the staging slot before you swap.');
});

test('the API rejects unknown fields when it creates a slot', function () {
    $this->withHeaders(slotsApiHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots", ['fqdn' => 'https://evil.example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fqdn']);
});

test('a swap needs the deploy ability', function () {
    $this->withHeaders(slotsApiHeaders($this->token))->postJson("/api/v1/applications/{$this->application->uuid}/slots")->assertCreated();
    slotsApiRunningPair($this->application);
    $writeOnly = $this->user->createToken('write-only', ['write'])->plainTextToken;
    app('auth')->forgetGuards();

    $this->withHeaders(slotsApiHeaders($writeOnly))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots/swap")
        ->assertForbidden();

    expect($this->application->fresh()->slots->first()->fqdn)->not->toBe('https://shop.example.com');
});

test('a member cannot create or swap slots', function () {
    $this->withHeaders(slotsApiHeaders($this->token))->postJson("/api/v1/applications/{$this->application->uuid}/slots")->assertCreated();
    slotsApiRunningPair($this->application);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'admin']);
    $memberToken = $member->createToken('member', ['write', 'deploy'])->plainTextToken;
    $this->team->members()->updateExistingPivot($member->id, ['role' => 'member']);
    app('auth')->forgetGuards();

    $this->withHeaders(slotsApiHeaders($memberToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots")
        ->assertForbidden();
    $this->withHeaders(slotsApiHeaders($memberToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots/swap")
        ->assertForbidden();
    expect($this->application->fresh()->slots->first()->fqdn)->not->toBe('https://shop.example.com');
});

test('another team cannot reach the slots of an application', function () {
    $otherTeam = Team::factory()->create();
    $other = User::factory()->create();
    $otherTeam->members()->attach($other->id, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);
    $otherToken = $other->createToken('other', ['write', 'deploy'])->plainTextToken;

    $this->withHeaders(slotsApiHeaders($otherToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots")
        ->assertNotFound();
    $this->withHeaders(slotsApiHeaders($otherToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/slots/swap")
        ->assertNotFound();
    expect($this->application->fresh()->slots)->toBeEmpty();
});
