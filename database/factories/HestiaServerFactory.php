<?php

namespace Database\Factories;

use App\Domains\Hestia\Models\HestiaServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HestiaServer>
 */
class HestiaServerFactory extends Factory
{
    protected $model = HestiaServer::class;

    public function definition(): array
    {
        $name = fake()->unique()->userName();

        return [
            'name' => $name,
            'code' => HestiaServer::makeCode($name),
            'host' => $name.'.test',
            'port' => 8083,
            'scheme' => 'https',
            'verify_ssl' => false,
            'timeout' => 30,
            'is_active' => true,
            'notes' => null,
        ];
    }

    /** Server dengan kredensial user/password (nilai dummy untuk tes). */
    public function withPassword(string $user = 'admin', string $password = 'rahasia-factory-test'): static
    {
        return $this->state(fn () => [
            'credentials' => ['user' => $user, 'password' => $password],
        ]);
    }

    /** Server dengan kredensial access/secret key (nilai dummy untuk tes). */
    public function withAccessKey(string $accessKey = 'AK-123', string $secretKey = 'SK-456'): static
    {
        return $this->state(fn () => [
            'credentials' => ['access_key' => $accessKey, 'secret_key' => $secretKey],
        ]);
    }
}
