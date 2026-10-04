<?php

namespace Tests\Feature\Access;

use App\Domains\Access\Enums\Module;
use App\Domains\Access\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        // User tanpa role = akses penuh (legacy), jadi bisa mengelola role.
        return tap(User::factory()->create(), fn (User $u) => $this->actingAs($u));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('roles.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_roles_access_is_forbidden(): void
    {
        $role = Role::factory()->views([Module::Clients])->create();
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->get(route('roles.index'))->assertForbidden();
    }

    public function test_can_create_role_with_per_module_access(): void
    {
        $this->actingUser();

        $response = $this->post(route('roles.store'), [
            'name' => 'operator',
            'label' => 'Operator',
            'description' => 'Tim operasional',
            'permissions' => [
                'clients' => 'manage',
                'invoices' => 'view',
                'reports' => 'manage', // modul baca-saja → diturunkan ke view
                'unknown-module' => 'manage', // harus diabaikan
            ],
        ]);

        $response->assertRedirect(route('roles.index'));
        $this->assertDatabaseHas('roles', ['name' => 'operator', 'label' => 'Operator']);

        $role = Role::where('name', 'operator')->firstOrFail();
        $this->assertSame('manage', $role->permissions['clients']);
        $this->assertSame('view', $role->permissions['invoices']);
        $this->assertSame('view', $role->permissions['reports']);
        $this->assertArrayNotHasKey('unknown-module', $role->permissions);
    }

    public function test_providers_module_can_be_granted_via_role_form(): void
    {
        // t_d85674c4: modul `providers` (dulu dipakai sidebar tapi belum ada di
        // enum) harus tersimpan lewat form role, bukan dibuang sebagai modul asing.
        $this->actingUser();

        $this->post(route('roles.store'), [
            'name' => 'domain-ops',
            'label' => 'Domain Ops',
            'permissions' => ['providers' => 'manage'],
        ])->assertRedirect(route('roles.index'));

        $role = Role::where('name', 'domain-ops')->firstOrFail();
        $this->assertSame(['providers' => 'manage'], $role->permissions);
        $this->assertTrue($role->grantsModule(Module::Providers));
    }

    public function test_name_and_label_are_required(): void
    {
        $this->actingUser();

        $this->post(route('roles.store'), ['name' => '', 'label' => ''])
            ->assertSessionHasErrors(['name', 'label']);

        $this->assertDatabaseCount('roles', 0);
    }

    public function test_name_must_be_unique(): void
    {
        $this->actingUser();
        Role::factory()->create(['name' => 'operator']);

        $this->post(route('roles.store'), ['name' => 'operator', 'label' => 'Dup'])
            ->assertSessionHasErrors('name');
    }

    public function test_can_update_role(): void
    {
        $this->actingUser();
        $role = Role::factory()->views([Module::Clients])->create(['name' => 'staf']);

        $this->put(route('roles.update', $role), [
            'name' => 'staf',
            'label' => 'Staf Baru',
            'permissions' => ['tasks' => 'manage'],
        ])->assertRedirect(route('roles.index'));

        $role->refresh();
        $this->assertSame('Staf Baru', $role->label);
        $this->assertSame(['tasks' => 'manage'], $role->permissions);
    }

    public function test_administrator_role_cannot_be_deleted(): void
    {
        $this->actingUser();
        $role = Role::factory()->admin()->create();

        $this->delete(route('roles.destroy', $role))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $this->actingUser();
        $role = Role::factory()->create();
        User::factory()->create(['role_id' => $role->id]);

        $this->delete(route('roles.destroy', $role))->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_unused_role_can_be_deleted(): void
    {
        $this->actingUser();
        $role = Role::factory()->create();

        $this->delete(route('roles.destroy', $role))
            ->assertRedirect(route('roles.index'));

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_non_admin_cannot_create_admin_role(): void
    {
        $manager = Role::factory()->manages([Module::Clients, Module::Roles])->create();
        $this->actingAs(User::factory()->create(['role_id' => $manager->id]));

        $this->post(route('roles.store'), [
            'name' => 'sneaky-admin',
            'label' => 'Sneaky',
            'is_admin' => '1',
        ])->assertRedirect(route('roles.index'));

        $this->assertFalse(Role::where('name', 'sneaky-admin')->firstOrFail()->is_admin);
    }

    public function test_administrator_flag_cannot_be_stripped_from_admin_role(): void
    {
        $this->actingUser();
        $role = Role::factory()->admin()->create(['name' => 'administrator']);

        $this->put(route('roles.update', $role), [
            'name' => 'administrator',
            'label' => 'Administrator',
            // is_admin sengaja tidak dikirim
        ]);

        $this->assertTrue($role->fresh()->is_admin);
    }
}
