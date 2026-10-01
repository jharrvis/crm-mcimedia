<?php

namespace Database\Factories;

use App\Domains\Tasks\Enums\TaskPriority;
use App\Domains\Tasks\Enums\TaskStatus;
use App\Domains\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        $due = fake()->optional()->dateTimeBetween('-5 days', '+14 days');

        return [
            'title' => fake()->sentence(5),
            'description' => fake()->optional()->sentence(),
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'due_date' => $due?->format('Y-m-d'),
            'status' => TaskStatus::Open,
        ];
    }
}
