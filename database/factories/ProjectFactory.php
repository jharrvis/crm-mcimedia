<?php

namespace Database\Factories;

use App\Domains\Clients\Models\Client;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $deadline = fake()->optional()->dateTimeBetween('now', '+90 days');

        return [
            'client_id' => Client::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'deadline' => $deadline?->format('Y-m-d'),
            'status' => fake()->randomElement([ProjectStatus::New, ProjectStatus::InProgress]),
            'value' => fake()->randomElement([0, 2500000, 5000000, 15000000]),
        ];
    }
}
