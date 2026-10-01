<?php

namespace Database\Factories;

use App\Domains\Clients\Models\Client;
use App\Domains\Clients\Models\ClientContact;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientContactFactory extends Factory
{
    protected $model = ClientContact::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->name(),
            'role' => fake()->optional()->jobTitle(),
            'email' => fake()->unique()->safeEmail(),
            'whatsapp' => '628'.fake()->numerify('##########'),
        ];
    }
}
