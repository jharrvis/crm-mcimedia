<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\SecurityIncidentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityIncidentTest extends TestCase
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
        $this->get(route('security.incidents.index'))->assertRedirect(route('login'));
        $this->get(route('security.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_incidents(): void
    {
        $this->login();
        $incident = SecurityIncidentFactory::new()->create(['title' => 'Percobaan login gagal massal']);

        $response = $this->get(route('security.incidents.index'));

        $response->assertOk();
        $response->assertSee('Percobaan login gagal massal');
        $response->assertSee($incident->client->name);
    }

    public function test_can_create_incident(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('security.incidents.store'), [
            'client_id' => $client->id,
            'occurred_at' => '2026-09-30 02:15',
            'severity' => 'high',
            'source' => 'firewall',
            'title' => 'Brute force SSH dari IP asing',
            'description' => 'fail2ban memblokir 320 percobaan.',
            'status' => 'open',
        ]);

        $response->assertRedirect(route('security.incidents.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('security_incidents', [
            'client_id' => $client->id,
            'severity' => 'high',
            'source' => 'firewall',
            'title' => 'Brute force SSH dari IP asing',
            'status' => 'open',
            'resolved_at' => null,
        ]);
    }

    public function test_create_validates_required_and_enum_fields(): void
    {
        $this->login();

        $this->post(route('security.incidents.store'), [
            'client_id' => 999,
            'occurred_at' => '',
            'severity' => 'pancake',
            'source' => 'satellite',
            'title' => '',
            'status' => 'unknown',
        ])->assertSessionHasErrors(['client_id', 'occurred_at', 'severity', 'source', 'title', 'status']);

        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_resolving_sets_resolved_at_and_reopening_clears_it(): void
    {
        $this->login();
        $incident = SecurityIncidentFactory::new()->create(['status' => IncidentStatus::Open]);

        $this->put(route('security.incidents.update', $incident), [
            'client_id' => $incident->client_id,
            'occurred_at' => $incident->occurred_at->format('Y-m-d H:i'),
            'severity' => 'critical',
            'source' => 'manual',
            'title' => 'Insiden uji',
            'status' => 'resolved',
        ])->assertSessionHas('success');

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertNotNull($incident->resolved_at);

        $this->put(route('security.incidents.update', $incident), [
            'client_id' => $incident->client_id,
            'occurred_at' => $incident->occurred_at->format('Y-m-d H:i'),
            'severity' => 'critical',
            'source' => 'manual',
            'title' => 'Insiden uji',
            'status' => 'open',
        ]);

        $incident->refresh();
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertNull($incident->resolved_at);
    }

    public function test_incident_changes_are_written_to_activity_log(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $this->post(route('security.incidents.store'), [
            'client_id' => $client->id,
            'occurred_at' => now()->format('Y-m-d H:i'),
            'severity' => IncidentSeverity::Low->value,
            'source' => IncidentSource::Manual->value,
            'title' => 'Catatan manual',
            'status' => IncidentStatus::Open->value,
        ]);

        $incident = SecurityIncident::firstOrFail();
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $incident->getMorphClass(),
            'subject_id' => $incident->id,
            'event' => 'created',
        ]);
    }

    public function test_can_delete_incident(): void
    {
        $this->login();
        $incident = SecurityIncidentFactory::new()->create();

        $this->delete(route('security.incidents.destroy', $incident))->assertSessionHas('success');

        $this->assertDatabaseMissing('security_incidents', ['id' => $incident->id]);
    }

    public function test_show_renders_incident_detail(): void
    {
        $this->login();
        SecurityIncidentFactory::new()->create(['title' => 'Detail insiden uji']);

        $incident = SecurityIncident::query()->sole();

        $response = $this->get(route('security.incidents.show', $incident));

        $response->assertOk();
        $response->assertSee('Detail insiden uji');
    }

    public function test_api_endpoint_returns_filtered_incidents(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        SecurityIncidentFactory::new()->create(['client_id' => $client->id, 'severity' => IncidentSeverity::Critical, 'status' => IncidentStatus::Open, 'title' => 'Insiden A kritis']);
        SecurityIncidentFactory::new()->create(['client_id' => $client->id, 'severity' => IncidentSeverity::Low, 'status' => IncidentStatus::Resolved, 'title' => 'Insiden B rendah']);

        // Test without filters
        $response = $this->getJson(route('security.incidents.api'));
        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'current_page',
            'last_page',
            'total',
            'per_page',
            'filters',
        ]);
        $this->assertEquals(2, $response->json('total'));

        // Test with status filter
        $response = $this->getJson(route('security.incidents.api', ['status' => 'open']));
        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('open', $response->json('data.0.status.value'));

        // Test with severity filter
        $response = $this->getJson(route('security.incidents.api', ['severity' => 'critical']));
        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('critical', $response->json('data.0.severity.value'));

        // Test with client_id filter
        $response = $this->getJson(route('security.incidents.api', ['client_id' => $client->id]));
        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));

        // Test with search filter
        $response = $this->getJson(route('security.incidents.api', ['q' => 'kritis']));
        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));

        // Test combined filters
        $response = $this->getJson(route('security.incidents.api', [
            'client_id' => $client->id,
            'severity' => 'critical',
            'status' => 'open',
        ]));
        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));

        // Test data structure includes required fields for JS rendering
        $response = $this->getJson(route('security.incidents.api'));
        $response->assertOk();
        $first = $response->json('data.0');
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('occurred_at', $first);
        $this->assertArrayHasKey('client', $first);
        $this->assertArrayHasKey('severity', $first);
        $this->assertArrayHasKey('source', $first);
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayHasKey('description', $first);
        $this->assertArrayHasKey('status', $first);
        $this->assertArrayHasKey('edit_url', $first);
        $this->assertArrayHasKey('destroy_url', $first);
        $this->assertArrayHasKey('value', $first['severity']);
        $this->assertArrayHasKey('label', $first['severity']);
        $this->assertArrayHasKey('badgeClass', $first['severity']);
        $this->assertArrayHasKey('value', $first['status']);
        $this->assertArrayHasKey('label', $first['status']);
        $this->assertArrayHasKey('badgeClass', $first['status']);
    }

    public function test_index_filters_by_client_severity_and_status(): void
    {
        $this->login();
        $a = ClientFactory::new()->create();
        $b = ClientFactory::new()->create();

        SecurityIncidentFactory::new()->create(['client_id' => $a->id, 'severity' => IncidentSeverity::Critical, 'status' => IncidentStatus::Open, 'title' => 'Insiden A kritis']);
        SecurityIncidentFactory::new()->create(['client_id' => $b->id, 'severity' => IncidentSeverity::Low, 'status' => IncidentStatus::Resolved, 'title' => 'Insiden B rendah']);

        $response = $this->get(route('security.incidents.index', [
            'client_id' => $a->id,
            'severity' => 'critical',
            'status' => 'open',
        ]));

        $response->assertOk();
        $response->assertSee('Insiden A kritis');
        $response->assertDontSee('Insiden B rendah');
    }
}
