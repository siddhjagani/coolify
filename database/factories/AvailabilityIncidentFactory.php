<?php

namespace Database\Factories;

use App\Enums\AvailabilityIncidentReason;
use App\Models\AvailabilityIncident;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class AvailabilityIncidentFactory extends Factory
{
    protected $model = AvailabilityIncident::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'reason' => AvailabilityIncidentReason::UNREACHABLE,
            'started_at' => now()->subHour(),
            'ended_at' => now()->subMinutes(30),
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => ['ended_at' => null]);
    }
}
