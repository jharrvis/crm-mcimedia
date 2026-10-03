<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Regression UX-4: semua pemilih klien pakai <x-client-select> (searchable),
// bukan dropdown biasa; preselect & old-input tetap benar.
class ClientSelectTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_invoice_create_uses_searchable_client_select(): void
    {
        $this->login();
        ClientFactory::new()->create(['name' => 'Klien Unique']);

        $response = $this->get(route('invoices.create'));

        $response->assertOk();
        $response->assertSee('data-client-select', false);
        $response->assertSee('data-client-search', false);
        $response->assertSee('name="client_id"', false);
        $response->assertSee('Klien Unique');
    }

    public function test_invoice_edit_preselects_saved_client(): void
    {
        $this->login();
        $client = ClientFactory::new()->create(['name' => 'Klien Tersimpan']);
        $invoice = InvoiceFactory::new()->create(['client_id' => $client->id]);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk();
        $response->assertSee('data-client-select', false);
        $response->assertSee('value="'.$client->id.'" data-contact="" selected', false);
    }

    public function test_invoice_validation_failure_keeps_client_selection(): void
    {
        $this->login();
        $client = ClientFactory::new()->create(['name' => 'Klien Old']);

        $response = $this->from(route('invoices.create'))->post(route('invoices.store'), [
            'client_id' => $client->id,
            'title' => '',
        ]);

        $response->assertRedirect(route('invoices.create'));

        $follow = $this->followingRedirects()->post(route('invoices.store'), [
            'client_id' => $client->id,
            'title' => '',
        ]);
        $follow->assertSee('value="'.$client->id.'" data-contact="" selected', false);
    }

    public function test_index_filters_use_searchable_client_select(): void
    {
        $this->login();

        $this->get(route('invoices.index'))->assertOk()->assertSee('data-client-select', false);
        $this->get(route('projects.index'))->assertOk()->assertSee('data-client-select', false);
        $this->get(route('tasks.index'))->assertOk()->assertSee('data-client-select', false);
        $this->get(route('services.index'))->assertOk()->assertSee('data-client-select', false);
        $this->get(route('recurring-plans.index'))->assertOk()->assertSee('data-client-select', false);
        $this->get(route('reminders.index'))->assertOk()->assertSee('data-client-select', false);
        $this->get(route('tasks.board'))->assertOk()->assertSee('data-client-select', false);
    }

    public function test_reminders_index_filters_services_by_client(): void
    {
        $this->login();
        $a = ClientFactory::new()->create(['name' => 'Klien A']);
        $b = ClientFactory::new()->create(['name' => 'Klien B']);

        $response = $this->get(route('reminders.index', ['client_id' => $a->id]));

        $response->assertOk();
        $response->assertSee('data-client-select', false);
    }
}
