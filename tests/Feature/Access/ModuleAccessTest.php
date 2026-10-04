<?php

namespace Tests\Feature\Access;

use App\Domains\Access\Enums\Module;
use App\Domains\Access\Models\Role;
use App\Domains\Clients\Models\Client;
use App\Domains\Hestia\Models\HestiaServer;
use App\Domains\Invoicing\Models\RecurringPlan;
use App\Domains\Projects\Models\Project;
use App\Domains\Providers\Models\DomainProvider;
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

    // ------------------------------------------------------------------
    // t_d85674c4: grup route modul yang sebelumnya hanya memakai `auth`
    // (hestia/servers, domain-providers, recurring-plans) kini digate
    // `permission:<modul>` sesuai pemetaan sidebar.
    // ------------------------------------------------------------------

    public function test_hestia_server_routes_are_gated_by_hestia_module(): void
    {
        // Role tanpa modul hestia → seluruh aksi ditolak.
        $role = Role::factory()->views([Module::Clients])->create();
        $this->actingAs($this->userWith($role));

        $server = HestiaServer::factory()->create();

        $this->get(route('hestia.servers.index'))->assertForbidden();
        $this->get(route('hestia.servers.show', $server))->assertForbidden();
        $this->get(route('hestia.servers.create'))->assertForbidden();
        $this->get(route('hestia.servers.edit', $server))->assertForbidden();
        $this->post(route('hestia.servers.store'), [])->assertForbidden();
        $this->put(route('hestia.servers.update', $server), ['name' => 'Diubah'])->assertForbidden();
        $this->delete(route('hestia.servers.destroy', $server))->assertForbidden();

        // Endpoint sync (termasuk yang baru dari t_dcccffd9) ikut tertutup.
        $this->post(route('hestia.servers.test', $server))->assertForbidden();
        $this->post(route('hestia.servers.sync', $server))->assertForbidden();
        $this->post(route('hestia.servers.sync.start', $server))->assertForbidden();
        $this->post(route('hestia.servers.sync.batch', $server))->assertForbidden();

        // Tidak ada efek samping: server tidak berubah/terhapus.
        $this->assertDatabaseHas('hestia_servers', ['id' => $server->id, 'name' => $server->name]);
    }

    public function test_hestia_sync_ajax_gets_friendly_forbidden_message(): void
    {
        $role = Role::factory()->views([Module::Clients])->create();
        $this->actingAs($this->userWith($role));

        $server = HestiaServer::factory()->create();

        // Endpoint AJAX membalas JSON 403 dengan pesan middleware, bukan HTML.
        $this->postJson(route('hestia.servers.sync.start', $server))
            ->assertForbidden()
            ->assertJson(['message' => 'Anda tidak memiliki akses ke modul ini.']);
    }

    public function test_hestia_server_view_role_can_see_but_not_manage(): void
    {
        $role = Role::factory()->views([Module::Hestia])->create();
        $this->actingAs($this->userWith($role));

        $server = HestiaServer::factory()->create();

        $this->get(route('hestia.servers.index'))->assertOk();
        $this->get(route('hestia.servers.show', $server))->assertOk();

        // Aksi tulis & sinkronisasi butuh "kelola".
        $this->get(route('hestia.servers.create'))->assertForbidden();
        $this->post(route('hestia.servers.sync.start', $server))->assertForbidden();
        $this->delete(route('hestia.servers.destroy', $server))->assertForbidden();
    }

    public function test_hestia_server_manage_role_and_admin_pass(): void
    {
        $server = HestiaServer::factory()->create();

        $manage = Role::factory()->manages([Module::Hestia])->create();
        $this->actingAs($this->userWith($manage));
        $this->get(route('hestia.servers.create'))->assertOk();

        $this->actingAs($this->userWith(Role::factory()->admin()->create()));
        $this->get(route('hestia.servers.index'))->assertOk();
        $this->get(route('hestia.servers.edit', $server))->assertOk();
    }

    public function test_domain_provider_routes_are_gated_by_providers_module(): void
    {
        $provider = DomainProvider::factory()->create();

        // Role tanpa modul providers → ditolak, termasuk baca kredensial provider.
        $this->actingAs($this->userWith(Role::factory()->views([Module::Clients])->create()));

        $this->get(route('domain-providers.index'))->assertForbidden();
        $this->get(route('domain-providers.create'))->assertForbidden();
        $this->post(route('domain-providers.store'), [])->assertForbidden();
        $this->get(route('domain-providers.edit', $provider))->assertForbidden();
        $this->put(route('domain-providers.update', $provider), ['name' => 'X'])->assertForbidden();
        $this->delete(route('domain-providers.destroy', $provider))->assertForbidden();
        $this->patch(route('domain-providers.toggle', $provider))->assertForbidden();
        $this->post(route('domain-providers.auto-renew', $provider), [])->assertForbidden();
        $this->post(route('domain-providers.import-services', $provider), [])->assertForbidden();
        $this->get(route('domain-providers.domains', $provider))->assertForbidden();

        // Role "lihat" → daftar & detail domain boleh, aksi tulis ditolak.
        $this->actingAs($this->userWith(Role::factory()->views([Module::Providers])->create()));

        $this->get(route('domain-providers.index'))->assertOk();
        $this->get(route('domain-providers.domains', $provider))->assertOk();
        $this->get(route('domain-providers.create'))->assertForbidden();
        $this->post(route('domain-providers.store'), [])->assertForbidden();

        // Administrator selalu lolos.
        $this->actingAs($this->userWith(Role::factory()->admin()->create()));
        $this->get(route('domain-providers.index'))->assertOk();
    }

    public function test_recurring_plan_routes_are_gated_by_invoices_module(): void
    {
        $plan = RecurringPlan::factory()->create();

        // Role tanpa modul invoices → ditolak untuk semua aksi paket.
        $this->actingAs($this->userWith(Role::factory()->views([Module::Clients])->create()));
        $this->get(route('recurring-plans.index'))->assertForbidden();
        $this->get(route('recurring-plans.show', $plan))->assertForbidden();
        $this->get(route('recurring-plans.edit', $plan))->assertForbidden();
        $this->post(route('recurring-plans.store'), [])->assertForbidden();
        $this->put(route('recurring-plans.update', $plan), [])->assertForbidden();
        $this->delete(route('recurring-plans.destroy', $plan))->assertForbidden();
        $this->patch(route('recurring-plans.toggle', $plan))->assertForbidden();
        $this->post(route('recurring-plans.generate-now', $plan))->assertForbidden();

        // Modul invoices membuka daftar; aksi kelola tetap butuh "manage".
        $this->actingAs($this->userWith(Role::factory()->views([Module::Invoices])->create()));
        $this->get(route('recurring-plans.index'))->assertOk();
        $this->get(route('recurring-plans.create'))->assertForbidden();

        $this->actingAs($this->userWith(Role::factory()->manages([Module::Invoices])->create()));
        $this->get(route('recurring-plans.create'))->assertOk();
    }

    public function test_gated_route_prefixes_never_lose_permission_middleware(): void
    {
        // Kunci kontrak: setiap route di bawah prefix ini WAJIB membawa
        // middleware permission: — route baru yang ditambahkan nanti tidak
        // boleh lolos tanpa gate modul.
        $expected = [
            'hestia.servers.' => 'permission:hestia',
            'domain-providers.' => 'permission:providers',
            'recurring-plans.' => 'permission:invoices',
        ];

        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();

            foreach ($expected as $prefix => $middleware) {
                if (! str_starts_with($name, $prefix)) {
                    continue;
                }

                $checked++;

                $this->assertContains(
                    $middleware,
                    $route->gatherMiddleware(),
                    "Route {$name} tidak membawa middleware {$middleware}.",
                );
            }
        }

        // 11 (hestia.servers) + 10 (domain-providers) + 9 (recurring-plans).
        $this->assertGreaterThanOrEqual(30, $checked);
    }
}
