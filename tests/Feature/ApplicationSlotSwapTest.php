<?php

use App\Actions\Application\CreateApplicationSlot;
use App\Actions\Application\SwapApplicationSlot;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProxyTypes;
use App\Exceptions\ApplicationSlotException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\FinishApplicationSlotSwapJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class, FinishApplicationSlotSwapJob::class]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], []));

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'running'],
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);

    $this->production = Application::factory()->create([
        'name' => 'shop',
        'fqdn' => 'https://shop.example.com',
        'status' => 'running:healthy',
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'custom_network_aliases' => 'shop-api',
        'is_http_basic_auth_enabled' => false,
    ]);
    $this->production->settings->update(['is_auto_deploy_enabled' => false]);
    ScheduledTask::factory()->create([
        'application_id' => $this->production->id,
        'team_id' => $this->team->id,
    ]);
    slotSwapDeployment($this->production, 'aaaa000000000000000000000000000000000000');

    $this->staging = CreateApplicationSlot::run($this->production->fresh());
    $this->staging->update([
        'status' => 'running:healthy',
        'is_http_basic_auth_enabled' => true,
        'http_basic_auth_username' => 'staging',
        'http_basic_auth_password' => 'secret-password',
    ]);
    $this->staging->settings->update(['is_auto_deploy_enabled' => true]);
    slotSwapDeployment($this->staging, 'bbbb000000000000000000000000000000000000');
    $this->stagingFqdn = $this->staging->fqdn;
});

function slotSwapDeployment(Application $application, string $commit, string $status = 'finished'): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => new_public_id(),
        'server_id' => $application->destination->server_id,
        'pull_request_id' => 0,
        'status' => $status,
        'commit' => $commit,
    ]);
}

function slotSwapJob(): FinishApplicationSlotSwapJob
{
    $job = null;
    Bus::assertDispatched(FinishApplicationSlotSwapJob::class, function (FinishApplicationSlotSwapJob $dispatched) use (&$job) {
        $job = $dispatched;

        return true;
    });

    return $job;
}

function decodedLabels(Application $application): string
{
    return base64_decode($application->fresh()->custom_labels);
}

test('creating a slot clones the application on the same server with its own domain', function () {
    expect($this->staging->slot_of_id)->toBe($this->production->id)
        ->and($this->staging->name)->toBe('shop-staging')
        ->and($this->staging->destination_id)->toBe($this->destination->id)
        ->and($this->stagingFqdn)->not->toBeEmpty()->not->toContain('shop.example.com')
        ->and($this->staging->custom_network_aliases)->toBeNull()
        ->and($this->staging->scheduled_tasks()->count())->toBe(0)
        ->and($this->production->fresh()->slotGroup()->pluck('id')->all())->toBe([$this->production->id, $this->staging->id]);
});

test('more slots join the same group, and a clone of a slot is not a slot', function () {
    $second = CreateApplicationSlot::run($this->production->fresh());
    $third = CreateApplicationSlot::run($this->staging->fresh(), 'shop-canary');

    expect($second->name)->toBe('shop-slot-2')
        ->and($second->slot_of_id)->toBe($this->production->id)
        ->and($third->name)->toBe('shop-canary')
        ->and($third->slot_of_id)->toBe($this->production->id)
        ->and($this->staging->fresh()->slotGroup()->pluck('id')->all())->toBe([$this->production->id, $this->staging->id, $second->id, $third->id]);

    $clone = clone_application($this->staging->fresh(), $this->destination, ['name' => 'copy']);

    expect($clone->slot_of_id)->toBeNull();
});

test('starting a swap gives the staging slot the production routing and restarts it at its running commit', function () {
    $deploymentUuid = SwapApplicationSlot::run($this->production);

    $staging = $this->staging->fresh();
    $production = $this->production->fresh();
    expect($staging->fqdn)->toBe('https://shop.example.com')
        ->and($staging->custom_network_aliases)->toBe($production->custom_network_aliases)
        ->and((bool) $staging->is_http_basic_auth_enabled)->toBeFalse()
        ->and($staging->settings->is_auto_deploy_enabled)->toBeFalse()
        ->and($staging->name)->toBe('shop-staging')
        ->and(decodedLabels($staging))->toContain('shop.example.com')
        // Production keeps serving its domains until the staging slot runs with them.
        ->and($production->fqdn)->toBe('https://shop.example.com')
        ->and($production->slots)->toHaveCount(1);

    $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', $deploymentUuid)->firstOrFail();
    expect($deployment->application_id)->toBe((string) $staging->id)
        ->and($deployment->commit)->toBe('bbbb000000000000000000000000000000000000')
        ->and((bool) $deployment->restart_only)->toBeTrue()
        ->and(slotSwapJob()->deploymentUuid)->toBe($deploymentUuid);
});

test('a finished staging deployment completes the swap', function () {
    $deploymentUuid = SwapApplicationSlot::run($this->staging);
    $job = slotSwapJob();
    ApplicationDeploymentQueue::query()->where('deployment_uuid', $deploymentUuid)->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);

    $job->handle();

    $newProduction = $this->staging->fresh();
    $newStaging = $this->production->fresh();
    expect($newProduction->name)->toBe('shop')
        ->and($newProduction->fqdn)->toBe('https://shop.example.com')
        ->and($newProduction->slot_of_id)->toBeNull()
        ->and($newProduction->scheduled_tasks()->count())->toBe(1)
        ->and($newStaging->name)->toBe('shop-staging')
        ->and($newStaging->fqdn)->toBe($this->stagingFqdn)
        ->and($newStaging->slot_of_id)->toBe($newProduction->id)
        ->and($newStaging->custom_network_aliases)->toBeNull()
        ->and((bool) $newStaging->is_http_basic_auth_enabled)->toBeTrue()
        ->and($newStaging->http_basic_auth_password)->toBe('secret-password')
        ->and($newStaging->settings->is_auto_deploy_enabled)->toBeTrue()
        ->and($newStaging->scheduled_tasks()->count())->toBe(0)
        ->and(decodedLabels($newStaging))->not->toContain('shop.example.com');

    $restart = ApplicationDeploymentQueue::query()->where('application_id', $newStaging->id)->latest('id')->firstOrFail();
    expect($restart->commit)->toBe('aaaa000000000000000000000000000000000000')
        ->and((bool) $restart->restart_only)->toBeTrue()
        ->and(Cache::has(SwapApplicationSlot::lockKey($this->production->id)))->toBeFalse();
});

test('a failed or cancelled staging deployment puts the staging routing back', function (string $status) {
    $deploymentUuid = SwapApplicationSlot::run($this->production);
    $job = slotSwapJob();
    ApplicationDeploymentQueue::query()->where('deployment_uuid', $deploymentUuid)->update(['status' => $status]);

    $job->handle();

    $staging = $this->staging->fresh();
    expect($staging->fqdn)->toBe($this->stagingFqdn)
        ->and($staging->slot_of_id)->toBe($this->production->id)
        ->and((bool) $staging->is_http_basic_auth_enabled)->toBeTrue()
        ->and($staging->settings->is_auto_deploy_enabled)->toBeTrue()
        ->and(decodedLabels($staging))->not->toContain('shop.example.com')
        ->and($this->production->fresh()->fqdn)->toBe('https://shop.example.com')
        ->and(Cache::has(SwapApplicationSlot::lockKey($this->production->id)))->toBeFalse();
})->with([
    ApplicationDeploymentStatus::FAILED->value,
    ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
]);

test('the finish job waits while the staging deployment runs', function () {
    SwapApplicationSlot::run($this->production);
    $job = slotSwapJob();
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertReleased(FinishApplicationSlotSwapJob::POLL_SECONDS);
    expect($this->production->fresh()->slots)->toHaveCount(1);
});

test('a timed out swap reverts', function () {
    SwapApplicationSlot::run($this->production);

    slotSwapJob()->failed(null);

    expect($this->staging->fresh()->fqdn)->toBe($this->stagingFqdn);
});

test('a second swap is refused while one runs', function () {
    SwapApplicationSlot::run($this->production);

    expect(fn () => SwapApplicationSlot::run($this->staging->fresh()))
        ->toThrow(fn (ApplicationSlotException $e) => expect($e->status)->toBe(409));
});

test('a swap is refused when the setup cannot move without downtime', function (Closure $setup, string $message) {
    $setup($this->production, $this->staging);

    expect(fn () => SwapApplicationSlot::run($this->production->fresh()))
        ->toThrow(ApplicationSlotException::class, $message);

    expect($this->staging->fresh()->fqdn)->toBe($this->stagingFqdn)
        ->and(Cache::has(SwapApplicationSlot::lockKey($this->production->id)))->toBeFalse();
})->with([
    'compose' => [fn (Application $p) => $p->update(['build_pack' => 'dockercompose']), 'Docker Compose'],
    'manual labels' => [fn (Application $p) => $p->settings->update(['is_container_label_readonly_enabled' => false]), 'managed manually'],
    'host ports' => [fn (Application $p) => $p->update(['ports_mappings' => '8080:3000']), 'maps ports to the host'],
    'stopped slot' => [fn (Application $p, Application $s) => $s->update(['status' => 'exited']), 'Start the staging slot'],
    'never deployed' => [fn (Application $p) => $p->deployment_queue()->delete(), 'no successful deployment'],
    'running deployment' => [fn (Application $p) => slotSwapDeployment($p, 'HEAD', 'in_progress'), 'queued or running'],
    'other server' => [function (Application $p, Application $s) {
        $server = Server::factory()->create(['team_id' => $p->team()->id, 'proxy' => ['type' => ProxyTypes::TRAEFIK->value]]);
        $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
        $s->update(['destination_id' => $destination->id]);
    }, 'same server'],
]);

test('a swap needs a slot', function () {
    $this->staging->forceDelete();

    expect(fn () => SwapApplicationSlot::run($this->production->fresh()))
        ->toThrow(fn (ApplicationSlotException $e) => expect($e->status)->toBe(404));
});
