<?php

namespace Tests\Feature\Services;

use App\Domains\Services\Models\Service;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get(route('services.index'))->assertRedirect(route('login'));
    }

    public function test_create_service_valid(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('services.store'), [
            'client_id' => $client->id,
            'type' => 'hosting',
            'name' => 'Hosting Bisnis',
            'reference' => 'contoh.com',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'price' => 600000,
            'cycle' => 'yearly',
            'status' => 'active',
            'reminder_enabled' => '1',
        ]);

        $this->assertDatabaseHas('services', [
            'client_id' => $client->id,
            'name' => 'Hosting Bisnis',
            'price' => 600000,
        ]);

        $service = Service::where('name', 'Hosting Bisnis')->first();
        $response->assertRedirect(route('services.show', $service));
        $response->assertSessionHas('success');
    }

    public function test_validation_end_date_before_start_date_fails(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('services.store'), [
            'client_id' => $client->id,
            'type' => 'domain',
            'name' => 'Domain test',
            'start_date' => now()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'cycle' => 'yearly',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('end_date');
        $this->assertDatabaseCount('services', 0);
    }

    public function test_index_filter_by_status(): void
    {
        $this->login();
        ServiceFactory::new()->create(['status' => 'active', 'name' => 'Aktif A']);
        ServiceFactory::new()->create(['status' => 'inactive', 'name' => 'Nonaktif B']);

        $response = $this->get(route('services.index', ['status' => 'inactive']));

        $response->assertOk();
        $response->assertSee('Nonaktif B');
        $response->assertDontSee('Aktif A');
    }

    public function test_expiring_soon_scope_returns_service_ending_in_10_days(): void
    {
        $this->login();
        $soon = ServiceFactory::new()->create(['end_date' => now()->addDays(10)->toDateString()]);
        ServiceFactory::new()->create(['end_date' => now()->addDays(90)->toDateString()]);
        ServiceFactory::new()->create(['end_date' => now()->subDays(5)->toDateString()]);

        $ids = Service::expiringSoon(30)->pluck('id')->all();

        $this->assertContains($soon->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_delete_service(): void
    {
        $this->login();
        $service = ServiceFactory::new()->create();

        $response = $this->delete(route('services.destroy', $service));

        $response->assertRedirect(route('services.index'));
        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }
}
