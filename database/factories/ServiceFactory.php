<?php

namespace Database\Factories;

use App\Domains\Clients\Models\Client;
use App\Domains\Services\Enums\ServiceCycle;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Enums\ServiceType;
use App\Domains\Services\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            // NB: Client::factory() tidak bisa resolve otomatis karena model memakai
            // namespace modular; pakai referensi langsung ke factory class.
            'client_id' => ClientFactory::new(),
            'type' => fake()->randomElement(ServiceType::cases()),
            'name' => fake()->words(3, true),
            'reference' => fake()->optional()->domainName(),
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => fake()->dateTimeBetween('-10 days', '+60 days')->format('Y-m-d'),
            'price' => fake()->randomElement([150000, 300000, 500000, 1200000, 2500000]),
            'cycle' => fake()->randomElement(ServiceCycle::cases()),
            'status' => ServiceStatus::Active,
            'reminder_enabled' => true,
        ];
    }
}
