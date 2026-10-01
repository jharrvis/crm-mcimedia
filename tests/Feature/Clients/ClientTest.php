<?php

namespace Tests\Feature\Clients;

use App\Domains\Clients\Models\Client;
use App\Domains\Clients\Models\ClientContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get(route('clients.index'))->assertRedirect(route('login'));
    }

    public function test_can_create_client(): void
    {
        $response = $this->post(route('clients.store'), [
            'name' => 'PT Contoh Sejahtera',
            'contact_name' => 'Budi',
            'email' => 'budi@contoh.id',
            'whatsapp' => '628123456789',
            'is_active' => '1',
        ]);

        $client = Client::where('name', 'PT Contoh Sejahtera')->firstOrFail();

        $response->assertRedirect(route('clients.show', $client));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('clients', ['name' => 'PT Contoh Sejahtera', 'email' => 'budi@contoh.id']);
    }

    public function test_name_is_required(): void
    {
        $this->post(route('clients.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_can_update_client(): void
    {
        $client = Client::factory()->create(['name' => 'Lama']);

        $this->put(route('clients.update', $client), [
            'name' => 'Baru',
            'is_active' => '1',
        ])->assertRedirect(route('clients.show', $client));

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'Baru']);
    }

    public function test_can_delete_client(): void
    {
        $client = Client::factory()->create();

        $this->delete(route('clients.destroy', $client))
            ->assertRedirect(route('clients.index'));

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_can_add_contact_to_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->post(route('clients.contacts.store', $client), [
            'name' => 'Siti',
            'role' => 'Finance',
            'email' => 'siti@contoh.id',
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseHas('client_contacts', [
            'client_id' => $client->id,
            'name' => 'Siti',
            'role' => 'Finance',
        ]);
    }

    public function test_can_delete_contact(): void
    {
        $client = Client::factory()->create();
        $contact = ClientContact::factory()->create(['client_id' => $client->id]);

        $this->delete(route('clients.contacts.destroy', [$contact->client_id, $contact]))
            ->assertRedirect(route('clients.show', $contact->client_id));

        $this->assertDatabaseMissing('client_contacts', ['id' => $contact->id]);
    }

    public function test_contact_must_belong_to_client(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $contact = ClientContact::factory()->create(['client_id' => $clientB->id]); // milik klien lain

        $this->delete(route('clients.contacts.destroy', [$clientA->id, $contact->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('client_contacts', ['id' => $contact->id]);
    }
}
