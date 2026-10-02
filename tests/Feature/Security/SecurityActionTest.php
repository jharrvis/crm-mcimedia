<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\SecurityActionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityActionTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('security.actions.index'))->assertRedirect(route('login'));
    }

    public function test_can_create_action_and_returns_it_on_index(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('security.actions.store'), [
            'client_id' => $client->id,
            'acted_at' => '2026-09-30',
            'action' => 'Menonaktifkan XML-RPC dan memasang aturan fail2ban baru.',
            'performed_by' => 'Tim MCI',
            'result' => 'Percobaan brute force turun ke nol.',
        ]);

        $response->assertRedirect(route('security.actions.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('security_actions', [
            'client_id' => $client->id,
            'action' => 'Menonaktifkan XML-RPC dan memasang aturan fail2ban baru.',
            'performed_by' => 'Tim MCI',
        ]);

        $this->get(route('security.actions.index'))
            ->assertOk()
            ->assertSee('Menonaktifkan XML-RPC dan memasang aturan fail2ban baru.');
    }

    public function test_create_requires_client_date_and_action(): void
    {
        $this->login();

        $this->post(route('security.actions.store'), [
            'client_id' => '',
            'acted_at' => '',
            'action' => '',
        ])->assertSessionHasErrors(['client_id', 'acted_at', 'action']);

        $this->assertDatabaseCount('security_actions', 0);
    }

    public function test_can_update_action(): void
    {
        $this->login();
        $action = SecurityActionFactory::new()->create();

        $this->put(route('security.actions.update', $action), [
            'client_id' => $action->client_id,
            'acted_at' => '2026-10-01',
            'action' => 'Pembaruan kata sandi admin WordPress.',
            'performed_by' => 'Muse',
            'result' => 'Selesai tanpa insiden.',
        ])->assertSessionHas('success');

        $this->assertSame('Pembaruan kata sandi admin WordPress.', $action->fresh()->action);
    }

    public function test_can_delete_action(): void
    {
        $this->login();
        $action = SecurityActionFactory::new()->create();

        $this->delete(route('security.actions.destroy', $action))->assertSessionHas('success');

        $this->assertDatabaseMissing('security_actions', ['id' => $action->id]);
    }

    public function test_index_filters_by_client(): void
    {
        $this->login();
        $a = ClientFactory::new()->create();
        $b = ClientFactory::new()->create();

        SecurityActionFactory::new()->create(['client_id' => $a->id, 'action' => 'AKSI_KLIEN_A']);
        SecurityActionFactory::new()->create(['client_id' => $b->id, 'action' => 'AKSI_KLIEN_B']);

        $this->get(route('security.actions.index', ['client_id' => $a->id]))
            ->assertOk()
            ->assertSee('AKSI_KLIEN_A')
            ->assertDontSee('AKSI_KLIEN_B');
    }
}
