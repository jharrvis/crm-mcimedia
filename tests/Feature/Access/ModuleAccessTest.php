<?php

namespace Tests\Feature\Access;

use App\Domains\Access\Enums\Module;
use App\Domains\Access\Models\Role;
use App\Domains\Clients\Models\Client;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(Role $role): User
    {
        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_legacy_user_without_role_has_full_access(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('tasks.index'))->assertOk();
        $this->get(route('clients.index'))->assertOk();
        $this->get(route('users.index'))->assertOk();
        $this->get(route('roles.index'))->assertOk();
    }

    public function test_administrator_role_bypasses_module_checks(): void
    {
        $role = Role::factory()->admin()->create();
        $this->actingAs($this->userWith($role));

        $this->get(route('security.index'))->assertOk();
        $this->get(route('hestia.index'))->assertOk();
    }

    public function test_view_only_role_can_see_but_not_write(): void
    {
        $role = Role::factory()->views([Module::Clients])->create();
        $this->actingAs($this->userWith($role));

        $this->get(route('clients.index'))->assertOk();
        $this->get(route('clients.create'))->assertForbidden();

        $this->post(route('clients.store'), ['name' => 'PT Tidak Boleh'])
            ->assertForbidden();

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_manage_role_can_create(): void
    {
        $role = Role::factory()->manages([Module::Clients])->create();
        $this->actingAs($this->userWith($role));

        $this->get(route('clients.create'))->assertOk();

        $this->post(route('clients.store'), ['name' => 'PT Boleh'])
            ->assertRedirect();

        $this->assertDatabaseHas('clients', ['name' => 'PT Boleh']);
    }

    public function test_module_without_access_is_forbidden(): void
    {
        $role = Role::factory()->views([Module::Clients])->create();
        $this->actingAs($this->userWith($role));

        $this->get(route('security.index'))->assertForbidden();
        $this->get(route('tasks.index'))->assertForbidden();
        $this->get(route('invoices.index'))->assertForbidden();
    }

    public function test_dashboard_is_always_accessible(): void
    {
        $role = Role::factory()->views([Module::Clients])->create();
        $this->actingAs($this->userWith($role));

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_sidebar_hides_inaccessible_modules(): void
    {
        $role = Role::factory()->views([Module::Clients])->create();
        $this->actingAs($this->userWith($role));

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(route('clients.index'), false);
        // Menu Pengaturan & modul tanpa akses tidak dirender di sidebar.
        $response->assertDontSee(route('users.index'), false);
        $response->assertDontSee(route('roles.index'), false);
        $response->assertDontSee(route('hestia.index'), false);
    }

    public function test_role_grants_per_module_levels(): void
    {
        $role = Role::factory()->create([
            'permissions' => ['clients' => 'manage', 'invoices' => 'view', 'reports' => 'view'],
        ]);

        $this->assertTrue($role->allows('clients', 'view'));
        $this->assertTrue($role->allows('clients', 'manage'));
        $this->assertTrue($role->allows('invoices', 'view'));
        $this->assertFalse($role->allows('invoices', 'manage'));
        $this->assertFalse($role->allows('tasks', 'view'));

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->assertTrue($user->hasPermission('clients', 'manage'));
        $this->assertFalse($user->hasPermission('invoices', 'manage'));
        $this->assertTrue($user->canAccessModule(Module::Invoices));
        $this->assertFalse($user->canAccessModule(Module::Tasks));
        $this->assertFalse($user->isAdmin());
    }

    public function test_leaked_role_scope_cannot_reach_settings_module(): void
    {
        $role = Role::factory()->manages([Module::Clients, Module::Invoices])->create();
        $this->actingAs($this->userWith($role));

        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('roles.index'))->assertForbidden();
        $this->get(route('users.create'))->assertForbidden();
    }

    public function test_nested_project_journal_and_report_are_gated_by_projects(): void
    {
        $role = Role::factory()->views([Module::Projects])->create();
        $this->actingAs($this->userWith($role));

        // POST jurnal butuh "manage", user hanya "view".
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        $this->post(route('projects.journals.store', $project), ['body' => 'x'])
            ->assertForbidden();
    }
}
