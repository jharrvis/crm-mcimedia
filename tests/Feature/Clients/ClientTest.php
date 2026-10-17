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

    public function test_client_show_uses_contact_modal_not_create_link(): void
    {
        $client = Client::factory()->create();

        $response = $this->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertDontSee(route('clients.contacts.create', $client), false);
        $response->assertSee(route('clients.contacts.store', $client), false); // form di dalam modal
        $response->assertSee('x-data="{ open:', false); // state Alpine modal
    }

    public function test_failed_contact_validation_reopens_modal_with_errors(): void
    {
        $client = Client::factory()->create();

        // Submit dari halaman detail klien (Referer = asal form), seperti klik "Simpan" di modal.
        $this->from(route('clients.show', $client))
            ->post(route('clients.contacts.store', $client), ['name' => ''])
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('client_contacts', 0);

        // Halaman tujuan: modal terbuka otomatis + pesan error terlihat.
        $this->followingRedirects()
            ->from(route('clients.show', $client))
            ->post(route('clients.contacts.store', $client), ['name' => ''])
            ->assertOk()
            ->assertSee('x-data="{ open: true }"', false)
            ->assertSee('The name field is required.', false); // pesan error per field dari x-input
    }

    public function test_successful_contact_creation_redirects_with_success_message(): void
    {
        $client = Client::factory()->create();

        $this->post(route('clients.contacts.store', $client), [
            'name' => 'Andi',
            'role' => 'Tech',
            'email' => 'andi@contoh.id',
        ])->assertRedirect(route('clients.show', $client))->assertSessionHas('success');

        $this->get(route('clients.show', $client->fresh()))
            ->assertOk()
            ->assertSee('Andi')
            ->assertSee('Kontak berhasil ditambahkan.');
    }
}
