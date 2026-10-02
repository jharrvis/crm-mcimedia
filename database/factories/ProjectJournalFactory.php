<?php

namespace Database\Factories;

use App\Domains\Projects\Enums\JournalCategory;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectJournal;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectJournalFactory extends Factory
{
    protected $model = ProjectJournal::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => null,
            'occurred_on' => now()->toDateString(),
            'category' => fake()->randomElement(JournalCategory::cases()),
            'body' => fake()->paragraph(),
        ];
    }
}
