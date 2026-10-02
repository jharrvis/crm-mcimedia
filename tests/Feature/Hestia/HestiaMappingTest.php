<?php

namespace Tests\Feature\Hestia;

use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Services\Models\Service;
use App\Models\User;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HestiaMappingTest extends TestCase
{
    use InteractsWithHestia;
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function account(array $overrides = []): HestiaAccount
    {
        return HestiaAccount::create(array_merge([
            'external_key' => HestiaAccount::keyFor('mcimedia', 'belumcocok.com'),
            'hestia_user' => 'mcimedia',
            'domain' => 'belumcocok.com',
            'plan' => 'default',
            'service_type' => 'hosting',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'mapping_status' => 'unmapped',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $overrides));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('hestia.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_unmapped_accounts(): void
    {
        $this->login();
        $this->account(['domain' => 'belumcocok.com']);

        $this->get(route('hestia.index'))
            ->assertOk()
            ->assertSee('belumcocok.com')
            ->assertSee('Belum dipetakan');
    }

    public function test_map_account_to_client_creates_service_and_links_it(): void
    {
        $this->login();
        $account = $this->account();
        $client = ClientFactory::new()->create();

        $response = $this->patch(route('hestia.accounts.map', $account), [
            'client_id' => $client->id,
        ]);

        $response->assertRedirect(route('hestia.index'));
        $response->assertSessionHas('success');

        $account->refresh();
        $this->assertSame($client->id, $account->client_id);
        $this->assertSame('mapped', $account->mapping_status->value);
        $this->assertNotNull($account->service_id);

        $service = Service::findOrFail($account->service_id);
        $this->assertSame($client->id, $service->client_id);
        $this->assertSame('belumcocok.com', $service->reference);
        $this->assertSame('hosting', $service->type->value);
    }

    public function test_map_requires_valid_client(): void
    {
        $this->login();
        $account = $this->account();

        $this->patch(route('hestia.accounts.map', $account), [])
            ->assertSessionHasErrors('client_id');

        $this->assertSame('unmapped', $account->fresh()->mapping_status->value);
    }

    public function test_ignore_account_marks_it_ignored(): void
    {
        $this->login();
        $account = $this->account();

        $this->patch(route('hestia.accounts.ignore', $account))
            ->assertRedirect(route('hestia.index'))
            ->assertSessionHas('success');

        $this->assertSame('ignored', $account->fresh()->mapping_status->value);
    }

    public function test_ignore_is_rejected_for_account_that_already_has_service(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $account = $this->account(['mapping_status' => 'mapped', 'client_id' => $client->id]);
        $service = Service::create([
            'client_id' => $client->id,
            'type' => 'hosting',
            'name' => 'belumcocok.com',
            'reference' => 'belumcocok.com',
            'cycle' => 'yearly',
            'status' => 'active',
        ]);
        $account->update(['service_id' => $service->id]);

        $this->patch(route('hestia.accounts.ignore', $account))
            ->assertRedirect(route('hestia.index'))
            ->assertSessionHas('error');

        $this->assertSame('mapped', $account->fresh()->mapping_status->value);
    }

    public function test_sync_button_runs_sync_and_redirects_with_success(): void
    {
        $this->login();
        $this->configureHestia();
        ClientFactory::new()->create(['email' => 'admin@contoh.com']);
        $this->fakeHestia(
            ['mcimedia' => ['PACKAGE' => 'default']],
            ['mcimedia' => ['contoh.com' => ['IP' => '203.0.113.10', 'DATE' => '2026-01-01', 'SUSPENDED' => 'no']]]
        );

        $this->post(route('hestia.sync'))
            ->assertRedirect(route('hestia.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('hestia_accounts', ['domain' => 'contoh.com']);
    }
}
