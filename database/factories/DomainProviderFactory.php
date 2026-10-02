<?php

namespace Database\Factories;

use App\Domains\Providers\Models\DomainProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

class DomainProviderFactory extends Factory
{
    protected $model = DomainProvider::class;

    public function definition(): array
    {
        return [
            'name' => 'Provider '.fake()->unique()->numerify('####'),
            'driver' => 'manual',
            'credentials' => [
                'domains_json' => json_encode([
                    ['domain' => fake()->unique()->domainName(), 'expires_at' => now()->addYear()->toDateString()],
                ]),
            ],
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Provider driver HestiaCP dengan kredensial dummy.
     */
    public function hestia(): static
    {
        return $this->state(fn () => [
            'driver' => 'hestia',
            'credentials' => [
                'host' => 'panel.test',
                'port' => 8083,
                'verify_ssl' => false,
                'user' => 'admin',
                'password' => 'rahasia-test-123',
                'account' => 'mcimedia',
            ],
        ]);
    }

    /**
     * Provider driver Hostinger dengan kredensial dummy (token palsu).
     */
    public function hostinger(): static
    {
        return $this->state(fn () => [
            'driver' => 'hostinger',
            'credentials' => [
                'api_token' => 'token-hostinger-test-123',
                'base_url' => 'https://developers.hostinger.com',
                'timeout' => 30,
            ],
        ]);
    }

    /**
     * Provider driver NameSilo dengan API key palsu.
     *
     * $credentials menimpa nilai bawaan (mis. `enrich_details` => false agar
     * tes tidak melakukan request `getDomainInfo` tambahan).
     */
    public function namesilo(array $credentials = []): static
    {
        return $this->state(fn () => [
            'driver' => 'namesilo',
            'credentials' => array_merge([
                'api_key' => 'nsk-test-key-123456',
                'base_url' => 'https://www.namesilo.com/api',
                'enrich_details' => true,
                'timeout' => 20,
            ], $credentials),
        ]);
    }
}
