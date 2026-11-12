<?php

use App\Enums\ProxyTypes;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class, FinishApplicationSlotSwapJob::class]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], []));

    $this->team = Team::factory()->create();
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

function slotsPageUser(Team $team, string $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);

    return $user;
}

function slotsPageRunningSlot(Application $application): Application
{
    $slot = $application->fresh()->slot;
    $slot->update(['status' => 'running:healthy']);
    foreach ([$application, $slot] as $resource) {
        ApplicationDeploymentQueue::create([
            'application_id' => $resource->id,
            'deployment_uuid' => new_public_id(),
            'server_id' => $resource->destination->server_id,
            'pull_request_id' => 0,
            'status' => 'finished',
            'commit' => 'dddd000000000000000000000000000000000000',
        ]);
    }

    return $slot;
}

test('an admin creates a slot and swaps from the page', function () {
    $admin = slotsPageUser($this->team, 'admin');
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(DeploymentSlots::class, ['application' => $this->application])
        ->set('slotName', 'shop-green')
        ->call('createSlot')
        ->assertDispatched('success')
        ->assertSee('shop-green');

    $slot = slotsPageRunningSlot($this->application);
    Livewire::test(DeploymentSlots::class, ['application' => $slot])
        ->call('swap')
        ->assertDispatched('success')
        ->assertSee('Swap in progress');

    expect($slot->fresh()->fqdn)->toBe('https://shop.example.com');
});

test('a member cannot create or swap slots', function () {
    $admin = slotsPageUser($this->team, 'admin');
    $this->actingAs($admin);
    Livewire::test(DeploymentSlots::class, ['application' => $this->application])->call('createSlot');
    $slot = slotsPageRunningSlot($this->application);

    $member = slotsPageUser($this->team, 'member');
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(DeploymentSlots::class, ['application' => $this->application])
        ->call('swap')
        ->assertDispatched('error');

    expect($slot->fresh()->fqdn)->not->toBe('https://shop.example.com')
        ->and(Application::query()->count())->toBe(2);
});

test('a swap acts on the team of the application after a team switch', function () {
    $admin = slotsPageUser($this->team, 'admin');
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($admin->id, ['role' => 'owner']);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);
    Livewire::test(DeploymentSlots::class, ['application' => $this->application])->call('createSlot');
    $slot = slotsPageRunningSlot($this->application);

    $page = Livewire::test(DeploymentSlots::class, ['application' => $this->application]);
    session(['currentTeam' => $otherTeam]);
    $page->call('swap')->assertDispatched('success');

    expect($slot->fresh()->fqdn)->toBe('https://shop.example.com');
});

test('the configuration menu opens the Deployment Slots page', function () {
    $this->withoutVite();
    $owner = slotsPageUser($this->team, 'owner');
    Team::query()->update(['show_boarding' => false]);
    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);
    $environment = $this->application->environment;

    $this->get(route('project.application.deployment-slots', [
        'project_uuid' => $environment->project->uuid,
        'environment_uuid' => $environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]))
        ->assertOk()
        ->assertSee('Deployment Slots')
        ->assertSee('Create staging slot');
});
