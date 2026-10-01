<?php

namespace Tests\Feature\Dashboard;

use App\Domains\Services\Models\Service;
use App\Domains\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_expiring_service_and_urgent_task(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $service = Service::factory()->create([
            'name' => 'Hosting Hampir Habis',
            'end_date' => now()->addDays(10)->toDateString(),
        ]);
        $task = Task::factory()->create([
            'title' => 'Tugas Mendesak Sekali',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('Hosting Hampir Habis');
        $response->assertSee('Tugas Mendesak Sekali');
        $response->assertSee((string) $service->client->name);
    }

    public function test_dashboard_stats_are_visible(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')
            ->assertOk()
            ->assertSee('Klien aktif')
            ->assertSee('Layanan aktif')
            ->assertSee('Project berjalan')
            ->assertSee('Tugas terbuka');
    }
}
