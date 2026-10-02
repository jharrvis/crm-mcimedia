<?php

namespace Database\Factories;

use App\Domains\Security\Models\SecurityAction;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityActionFactory extends Factory
{
    protected $model = SecurityAction::class;

    public function definition(): array
    {
        return [
            'client_id' => ClientFactory::new(),
            'acted_at' => now()->toDateString(),
            'action' => fake()->sentence(6),
            'performed_by' => fake()->name(),
            'result' => fake()->optional()->sentence(5),
        ];
    }
}
