<?php

namespace Database\Factories;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityIncidentFactory extends Factory
{
    protected $model = SecurityIncident::class;

    public function definition(): array
    {
        return [
            // NB: sama seperti factory lain — referensi factory langsung untuk model modular.
            'client_id' => ClientFactory::new(),
            'external_id' => null,
            'occurred_at' => now()->subDay(),
            'severity' => IncidentSeverity::Medium,
            'source' => IncidentSource::Monitor,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => IncidentStatus::Open,
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => IncidentStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }
}
