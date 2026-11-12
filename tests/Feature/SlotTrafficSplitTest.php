<?php

use App\Actions\Application\CreateApplicationSlot;
use App\Actions\Application\SetSlotTrafficSplit;
use App\Actions\Application\SwapApplicationSlot;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProxyTypes;
use App\Exceptions\ApplicationSlotException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\FinishApplicationSlotSwapJob;
use App\Livewire\Project\Application\DeploymentSlots;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Proxy\SlotTrafficSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class, FinishApplicationSlotSwapJob::class]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'running'],
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->production = Application::factory()->create([
        'name' => 'shop',
        'fqdn' => 'https://shop.example.com',
        'status' => 'running:healthy',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'is_http_basic_auth_enabled' => true,
        'http_basic_auth_username' => 'shop',
        'http_basic_auth_password' => 'secret',
    ]);
    $this->blue = CreateApplicationSlot::run($this->production->fresh(), 'shop-blue');
    $this->green = CreateApplicationSlot::run($this->production->fresh(), 'shop-green');
    foreach ([$this->production, $this->blue, $this->green] as $application) {
        $application->update(['status' => 'running:healthy']);
        ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => new_public_id(),
            'server_id' => $this->server->id,
            'pull_request_id' => 0,
            'status' => 'finished',
            'commit' => 'eeee000000000000000000000000000000000000',
        ]);
    }
});

function slotSplitWeights(Application ...$applications): array
{
    return collect($applications)->mapWithKeys(fn (Application $application) => [$application->uuid => 0])->all();
}

function slotSplitSet(object $test, int $production, int $blue, int $green): void
{
    SetSlotTrafficSplit::run($test->production->fresh(), [
        $test->production->uuid => $production,
        $test->blue->uuid => $blue,
        $test->green->uuid => $green,
    ]);
}

test('the Traefik routing splits the production routers by weight and keeps their middlewares', function () {
    slotSplitSet($this, 50, 30, 20);

    $configuration = Yaml::parse(SlotTrafficSplit::traefikConfiguration($this->server->fresh()));
    $weighted = $configuration['http']['services']["coolify-slots-{$this->production->uuid}"]['weighted'];
    $https = $configuration['http']['routers']["coolify-slots-https-0-{$this->production->uuid}"];

    expect(collect($weighted['services'])->pluck('weight')->all())->toBe([50, 30, 20])
        ->and($weighted['services'][0]['name'])->toEndWith("-0-{$this->production->uuid}@docker")
        ->and($weighted['services'][1]['name'])->toEndWith("-0-{$this->blue->uuid}@docker")
        ->and($weighted['sticky']['cookie']['name'])->toBe("coolify_slot_{$this->production->uuid}")
        ->and($https['rule'])->toBe('Host(`shop.example.com`) && PathPrefix(`/`)')
        ->and($https['priority'])->toBe(strlen($https['rule']) + 1)
        ->and($https['service'])->toBe("coolify-slots-{$this->production->uuid}")
        ->and($https['tls'])->toBe(['certResolver' => 'letsencrypt'])
        ->and($https['middlewares'])->toContain("http-basic-auth-{$this->production->uuid}@docker")
        ->and($configuration['http']['routers'])->toHaveKey("coolify-slots-http-0-{$this->production->uuid}");
});

test('slots with no weight, on another server, or in maintenance get no routing', function () {
    slotSplitSet($this, 100, 0, 0);
    expect(SlotTrafficSplit::traefikConfiguration($this->server->fresh()))->toBeNull();

    slotSplitSet($this, 70, 30, 0);
    expect(Yaml::parse(SlotTrafficSplit::traefikConfiguration($this->server->fresh()))['http']['services']["coolify-slots-{$this->production->uuid}"]['weighted']['services'])->toHaveCount(2);

    $this->production->forceFill(['is_maintenance_enabled' => true])->save();
    expect(SlotTrafficSplit::traefikConfiguration($this->server->fresh()))->toBeNull();

    $this->production->forceFill(['is_maintenance_enabled' => false])->save();
    SetSlotTrafficSplit::run($this->production->fresh(), null);
    expect(SlotTrafficSplit::traefikConfiguration($this->server->fresh()))->toBeNull()
        ->and($this->blue->fresh()->settings->slot_traffic_weight)->toBeNull();
});

test('the Caddy routing is a sticky weighted handle in the base Caddyfile', function () {
    $this->server->proxy->set('type', ProxyTypes::CADDY->value);
    $this->server->save();
    slotSplitSet($this, 50, 30, 20);

    $caddyfile = $this->server->fresh()->caddyBaseCaddyfile();

    expect($caddyfile)->toContain('https://shop.example.com {')
        ->toContain("handle @coolify-slots-{$this->production->uuid}-0 {")
        ->toContain("reverse_proxy {$this->production->uuid}:3000 {$this->blue->uuid}:3000 {$this->green->uuid}:3000 {")
        ->toContain("lb_policy cookie coolify_slot_{$this->production->uuid} {")
        ->toContain('fallback weighted_round_robin 50 30 20');
});

test('the weights must be valid', function (Closure $weights, string $message) {
    expect(fn () => SetSlotTrafficSplit::run($this->production->fresh(), $weights($this)))
        ->toThrow(ApplicationSlotException::class, $message);

    expect($this->production->fresh()->settings->slot_traffic_weight)->toBeNull();
})->with([
    'not 100' => [fn ($t) => [$t->production->uuid => 50, $t->blue->uuid => 30, $t->green->uuid => 30], 'add up to 100'],
    'missing slot' => [fn ($t) => [$t->production->uuid => 50, $t->blue->uuid => 50], 'every application of the slot group'],
    'other application' => [fn ($t) => [$t->production->uuid => 50, $t->blue->uuid => 50, 'other' => 0, $t->green->uuid => 0], 'every application of the slot group'],
    'not a whole number' => [fn ($t) => [$t->production->uuid => '50', $t->blue->uuid => 50, $t->green->uuid => 0], 'whole number'],
    'never deployed' => [function ($t) {
        $t->green->deployment_queue()->delete();

        return [$t->production->uuid => 50, $t->blue->uuid => 0, $t->green->uuid => 50];
    }, 'no successful deployment'],
    'other server' => [function ($t) {
        $server = Server::factory()->create(['team_id' => $t->team->id, 'proxy' => ['type' => ProxyTypes::TRAEFIK->value]]);
        $t->green->update(['destination_id' => StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail()->id]);

        return [$t->production->uuid => 50, $t->blue->uuid => 0, $t->green->uuid => 50];
    }, 'runs on another server'],
]);

test('the traffic split cannot change during a swap', function () {
    Cache::put(SwapApplicationSlot::lockKey($this->production->id), true, 60);

    expect(fn () => slotSplitSet($this, 50, 50, 0))
        ->toThrow(fn (ApplicationSlotException $e) => expect($e->status)->toBe(409));
});

test('a swap with three slots promotes the chosen slot and moves the weights', function () {
    slotSplitSet($this, 60, 30, 10);
    expect(fn () => SwapApplicationSlot::run($this->production->fresh()))
        ->toThrow(ApplicationSlotException::class, 'more than one slot');

    $deploymentUuid = SwapApplicationSlot::run($this->production->fresh(), 'ui', $this->green);
    $job = null;
    Bus::assertDispatched(FinishApplicationSlotSwapJob::class, function ($dispatched) use (&$job) {
        $job = $dispatched;

        return true;
    });
    ApplicationDeploymentQueue::query()->where('deployment_uuid', $deploymentUuid)->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
    $job->handle();

    $newProduction = $this->green->fresh();
    expect($newProduction->slot_of_id)->toBeNull()
        ->and($newProduction->name)->toBe('shop')
        ->and($newProduction->fqdn)->toBe('https://shop.example.com')
        ->and($newProduction->settings->slot_traffic_weight)->toBe(60)
        ->and($this->production->fresh()->slot_of_id)->toBe($newProduction->id)
        ->and($this->production->fresh()->settings->slot_traffic_weight)->toBe(10)
        ->and($this->blue->fresh()->slot_of_id)->toBe($newProduction->id)
        ->and($this->blue->fresh()->settings->slot_traffic_weight)->toBe(30)
        ->and($newProduction->slotGroup()->pluck('id')->all())->toBe([$newProduction->id, $this->production->id, $this->blue->id]);
});

test('the API lists the slots and sets the traffic split', function () {
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $token = $user->createToken('slots', ['read', 'write', 'deploy'])->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'];

    $this->withHeaders($headers)
        ->patchJson("/api/v1/applications/{$this->blue->uuid}/slots/traffic", ['weights' => [$this->production->uuid => 80, $this->blue->uuid => 20, $this->green->uuid => 0]])
        ->assertOk()
        ->assertJsonPath('message', 'Traffic split saved.');
    $this->withHeaders($headers)
        ->patchJson("/api/v1/applications/{$this->production->uuid}/slots/traffic", ['weights' => [$this->production->uuid => 80, $this->blue->uuid => 30, $this->green->uuid => 0]])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The weights must add up to 100.');

    $this->withHeaders($headers)
        ->getJson("/api/v1/applications/{$this->green->uuid}/slots")
        ->assertOk()
        ->assertJsonPath('traffic_split', true)
        ->assertJsonPath('slots.0.uuid', $this->production->uuid)
        ->assertJsonPath('slots.0.role', 'production')
        ->assertJsonPath('slots.0.traffic_weight', 80)
        ->assertJsonPath('slots.1.traffic_weight', 20)
        ->assertJsonPath('slots.2.role', 'slot');
});

test('the traffic split needs the deploy ability and update on every slot', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $writeOnly = $owner->createToken('write', ['write'])->plainTextToken;
    $body = ['weights' => [$this->production->uuid => 50, $this->blue->uuid => 50, $this->green->uuid => 0]];

    $this->withHeaders(['Authorization' => 'Bearer '.$writeOnly])
        ->patchJson("/api/v1/applications/{$this->production->uuid}/slots/traffic", $body)
        ->assertForbidden();

    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'admin']);
    $memberToken = $member->createToken('member', ['write', 'deploy'])->plainTextToken;
    $this->team->members()->updateExistingPivot($member->id, ['role' => 'member']);
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => 'Bearer '.$memberToken])
        ->patchJson("/api/v1/applications/{$this->production->uuid}/slots/traffic", $body)
        ->assertForbidden();

    expect($this->production->fresh()->settings->slot_traffic_weight)->toBeNull();
});

test('the page saves and turns off the traffic split', function () {
    $admin = User::factory()->create();
    $this->team->members()->attach($admin->id, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(DeploymentSlots::class, ['application' => $this->production])
        ->assertSet("weights.{$this->production->uuid}", 100)
        ->set("weights.{$this->production->uuid}", 70)
        ->set("weights.{$this->blue->uuid}", 20)
        ->set("weights.{$this->green->uuid}", 10)
        ->call('saveTrafficSplit')
        ->assertDispatched('success')
        ->assertSee('20% of traffic');

    expect($this->blue->fresh()->settings->slot_traffic_weight)->toBe(20);

    Livewire::test(DeploymentSlots::class, ['application' => $this->blue])
        ->call('turnOffTrafficSplit')
        ->assertDispatched('success');

    expect($this->production->fresh()->settings->slot_traffic_weight)->toBeNull();
});
