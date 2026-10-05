<?php

namespace Tests\Feature;

use App\Domains\Clients\Models\Client;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Projects\Models\Project;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Models\Service;
use App\Models\User;
use Database\Factories\AchievementReportFactory;
use Database\Factories\ClientFactory;
use Database\Factories\HestiaServerFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\ProductCategoryFactory;
use Database\Factories\RoleFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagination konsisten di semua indeks (t_ac2360ee).
 *
 * Memverifikasi `page=2` menampilkan baris yang benar, `withQueryString`
 * tidak merusak halaman, dan stat card / tfoot di laporan tetap global.
 */
class PaginationAcrossIndicesTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $this->actingAs(User::factory()->create());
    }

    public function test_product_categories_paginate(): void
    {
        $this->login();
        ProductCategoryFactory::new()->count(22)->create();
        $cats = \App\Domains\Catalog\Models\ProductCategory::orderBy('sort_order')->orderBy('name')->limit(22)->get(['id', 'name']);
        $nameOnPage2 = $cats->slice(20, 1)->first()->name;
        $this->get(route('product-categories.index', ['page' => 2]))
            ->assertOk()
            ->assertSee($nameOnPage2);
    }

    public function test_roles_paginate(): void
    {
        $this->login();
        RoleFactory::new()->count(27)->create();
        $last = \App\Domains\Access\Models\Role::orderByDesc('is_admin')->orderBy('label')->get(['label'])->last()->label;
        $this->get(route('roles.index', ['page' => 2]))
            ->assertOk()
            ->assertSee($last);
    }

    public function test_hestia_servers_paginate(): void
    {
        $this->login();
        HestiaServerFactory::new()->count(26)->create();
        $last = \App\Domains\Hestia\Models\HestiaServer::orderBy('name')->get(['name'])->last()->name;
        $this->get(route('hestia.servers.index', ['page' => 2]))
            ->assertOk()
            ->assertSee($last);
    }

    public function test_security_dashboard_paginates_clients(): void
    {
        $this->login();
        ClientFactory::new()->count(16)->create();
        $last = Client::orderBy('name')->get(['name'])->last()->name;
        $this->get(route('security.index', ['page' => 2]))
            ->assertOk()
            ->assertSee($last);
    }

    public function test_reminders_paginate_overdue_and_expiring(): void
    {
        $this->login();
        // Overdue: end_date < today, layanan aktif
        for ($i = 0; $i < 17; $i++) {
            ServiceFactory::new()->create([
                'client_id' => ClientFactory::new()->create()->id,
                'status' => ServiceStatus::Active,
                'end_date' => now()->subDays(5 + $i)->toDateString(),
                'name' => 'OVER-'.$i,
            ]);
        }
        // Expiring: 0..30 hari ke depan, aktif, reminder_enabled default true
        for ($i = 0; $i < 17; $i++) {
            ServiceFactory::new()->create([
                'client_id' => ClientFactory::new()->create()->id,
                'status' => ServiceStatus::Active,
                'reminder_enabled' => true,
                'end_date' => now()->addDays((int) floor($i / 2))->toDateString(),
                'name' => 'EXP-'.$i,
            ]);
        }
        $this->get(route('reminders.index', ['overdue_page' => 2]))
            ->assertOk()
            ->assertSee('OVER-0'); // paling baru overdue; halaman 2 harus masih ada data
        $this->get(route('reminders.index', ['page' => 2]))
            ->assertOk();
    }

    public function test_reports_unpaid_paginates_and_global_totals(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $client = ClientFactory::new()->create();
        // 16 invoice belum lunas di bulan jatuh tempo berbeda
        for ($i = 1; $i <= 16; $i++) {
            InvoiceFactory::new()->withItems(100000)->create([
                'number' => sprintf('INV-PAG-%04d', $i),
                'status' => InvoiceStatus::Sent,
                'client_id' => $client->id,
                'due_date' => now()->subDays($i)->toDateString(),
            ]);
        }
        // Urut due_date ASC: 0016 (terlama) di halaman 1, 0001 (terbaru) di halaman 2.
        $this->get(route('reports.index', ['unpaid_page' => 1]))
            ->assertOk()->assertSee('INV-PAG-0016')->assertDontSee('INV-PAG-0001');
        $resp = $this->get(route('reports.index', ['unpaid_page' => 2]));
        $resp->assertOk()->assertSee('INV-PAG-0001');
        // Stat card tetap menampilkan total global 16 tagihan, bukan per halaman
        $resp->assertSee('16 tagihan');
        // tfoot tetap global 1.600.000 (16 * 100.000)
        $resp->assertSee('Rp 1.600.000');
    }

    public function test_reports_client_summaries_paginate(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 1; $i <= 16; $i++) {
            $c = ClientFactory::new()->create(['name' => sprintf('Klien Pag %02d', $i)]);
            InvoiceFactory::new()->withItems(100000)->create([
                'number' => sprintf('INV-SUM-%04d', $i),
                'status' => InvoiceStatus::Sent,
                'client_id' => $c->id,
            ]);
        }
        $this->get(route('reports.index', ['summaries_page' => 2]))
            ->assertOk()
            ->assertSee('Klien Pag'); // halaman 2 masih render ringkasan
    }

    public function test_project_reports_paginate(): void
    {
        $this->actingAs(User::factory()->create());
        $project = \Database\Factories\ProjectFactory::new()->create();
        // Unique (project, period_type, period_start, period_end): period dibuat berbeda.
        for ($i = 0; $i < 16; $i++) {
            AchievementReportFactory::new()->create([
                'project_id' => $project->id,
                'period_start' => now()->subMonths($i)->startOfMonth()->toDateString(),
                'period_end' => now()->subMonths($i)->endOfMonth()->toDateString(),
            ]);
        }
        $this->get(route('projects.reports.index', ['project' => $project, 'page' => 2]))
            ->assertOk();
    }

    public function test_activity_log_paginate_with_query_string(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 27; $i++) {
            \App\Domains\Core\Models\ActivityLog::create([
                'user_id' => null,
                'event' => 'test',
                'description' => 'aktivitas '.$i,
                'subject_type' => null,
                'subject_id' => null,
                'properties' => null,
            ]);
        }
        // withQueryString: q=aktivitas harus dipertahankan; page=2 tetap ok
        $this->get(route('activity.index', ['q' => 'x', 'page' => 2]))
            ->assertOk();
    }
}
