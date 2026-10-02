<?php

namespace Database\Factories;

use App\Domains\Access\Enums\AccessLevel;
use App\Domains\Access\Enums\Module;
use App\Domains\Access\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'role-'.fake()->unique()->numerify('####'),
            'label' => fake()->words(2, true),
            'description' => null,
            'permissions' => [],
            'is_admin' => false,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['is_admin' => true, 'permissions' => []]);
    }

    /**
     * Beri akses "kelola" ke modul-modul tertentu (otomatis 1 level per modul).
     *
     * @param  array<int, Module>  $modules
     */
    public function manages(array $modules): static
    {
        $permissions = [];

        foreach ($modules as $module) {
            $permissions[$module->value] = AccessLevel::Manage->value;
        }

        return $this->state(fn () => ['permissions' => $permissions]);
    }

    /**
     * @param  array<int, Module>  $modules
     */
    public function views(array $modules): static
    {
        $permissions = [];

        foreach ($modules as $module) {
            $permissions[$module->value] = AccessLevel::View->value;
        }

        return $this->state(fn () => ['permissions' => $permissions]);
    }
}
