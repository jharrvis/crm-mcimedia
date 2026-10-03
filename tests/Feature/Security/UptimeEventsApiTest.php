<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Services\Models\Service;
use Database\Factories\ClientFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UptimeEventsApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-security-token-123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'crm.security.api_enabled' => true,
            'crm.security.api_token' => self::TOKEN,
            'crm.security.dedup_window_minutes' => 1440,
            'crm.security.uptime_default_client_id' => null,
        ]);
    }

    /** @return array<string, string> */
    private function headers(string $token = self::TOKEN): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /** @return array<string, mixed> */
    private function downPayload(array $overrides = []): array
    {
        return array_merge([
            'monitor_id' => 'mon-123',
            'monitor_name' => 'Website Utama',
            'url' => 'https://example.com',
            'status' => 'down',
            'occurred_at' => now()->toIso8601String(),
            'message' => 'Connection timeout',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function upPayload(array $overrides = []): array
    {
        return array_merge([
            'monitor_id' => 'mon-123',
            'monitor_name' => 'Website Utama',
            'url' => 'https://example.com',
            'status' => 'up',
            'occurred_at' => now()->toIso8601String(),
            'message' => 'Recovered',
        ], $overrides);
    }

    // ---------- autentikasi ----------

    public function test_missing_token_is_rejected(): void
    {
        $this->postJson('/api/security/uptime-events', $this->downPayload())
            ->assertUnauthorized();
    }

    public function test_wrong_token_is_rejected(): void
    {
        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers('salah'))
            ->assertUnauthorized();
    }

    public function test_endpoint_unavailable_when_token_not_configured(): void
    {
        config(['crm.security.api_token' => '']);

        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers())
            ->assertStatus(503);
    }

    public function test_endpoint_unavailable_when_api_disabled(): void
    {
        config(['crm.security.api_enabled' => false]);

        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers())
            ->assertStatus(503);
    }

    // ---------- validasi payload ----------

    public function test_validates_required_fields(): void
    {
        $response = $this->postJson('/api/security/uptime-events', [], $this->headers());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['monitor_id', 'monitor_name', 'url', 'status']);
    }

    public function test_validates_status_enum(): void
    {
        $payload = $this->downPayload(['status' => 'invalid']);

        $response = $this->postJson('/api/security/uptime-events', $payload, $this->headers());

        $response->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_validates_url_format(): void
    {
        $payload = $this->downPayload(['url' => 'not-a-url']);

        $response = $this->postJson('/api/security/uptime-events', $payload, $this->headers());

        $response->assertStatus(422);
    }

    // ---------- domain resolution via services ----------

    public function test_resolves_client_via_service_domain(): void
    {
        $client = ClientFactory::new()->create(['name' => 'Client A']);
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers());

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'down_created']);

        $this->assertDatabaseHas('security_incidents', [
            'client_id' => $client->id,
            'source' => 'monitor',
            'severity' => 'high',
            'title' => '[example.com] Website down',
            'status' => 'open',
        ]);
    }

    public function test_uses_default_client_when_service_not_found(): void
    {
        $defaultClient = ClientFactory::new()->create(['name' => 'Default Client']);
        config(['crm.security.uptime_default_client_id' => $defaultClient->id]);

        $response = $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers());

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'down_created']);

        $this->assertDatabaseHas('security_incidents', [
            'client_id' => $defaultClient->id,
        ]);
    }

    public function test_uses_first_active_client_as_last_resort(): void
    {
        $client = ClientFactory::new()->create(['name' => 'Fallback Client', 'is_active' => true]);

        $response = $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers());

        $response->assertOk();

        $this->assertDatabaseHas('security_incidents', [
            'client_id' => $client->id,
        ]);
    }

    public function test_ignores_inactive_service(): void
    {
        $client = ClientFactory::new()->create(['name' => 'Client A']);
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'inactive', // tidak aktif
        ]);

        // Client A tetap aktif, jadi fallback ke client pertama yang aktif (Client A sendiri)
        // karena service-nya inactive tapi client-nya aktif
        $response = $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers());

        $response->assertOk();

        $this->assertDatabaseHas('security_incidents', [
            'client_id' => $client->id,
        ]);
    }

    public function test_extracts_domain_without_www(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        $payload = $this->downPayload(['url' => 'https://www.example.com/path?query=1']);

        $response = $this->postJson('/api/security/uptime-events', $payload, $this->headers());

        $response->assertOk();

        $this->assertDatabaseHas('security_incidents', [
            'client_id' => $client->id,
            'title' => '[example.com] Website down',
        ]);
    }

    // ---------- DOWN -> buat insiden ----------

    public function test_down_creates_incident_with_correct_attributes(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers());

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'down_created'])
            ->assertJsonStructure(['incident_id']);

        $incident = SecurityIncident::firstOrFail();
        $this->assertSame($client->id, $incident->client_id);
        $this->assertSame(IncidentSeverity::High, $incident->severity);
        $this->assertSame(IncidentSource::Monitor, $incident->source);
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertStringStartsWith('uptime-kuma:mon-123:', $incident->external_id);
        $this->assertStringEndsWith('] Website down', $incident->title);
    }

    public function test_down_is_idempotent_via_external_id(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // Request pertama
        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers())
            ->assertJson(['action' => 'down_created']);

        // Request kedua dengan payload sama -> harus duplicate
        $response = $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers());

        $response->assertOk()
            ->assertJson(['action' => 'down_duplicate']);

        $this->assertDatabaseCount('security_incidents', 1);
    }

    public function test_down_different_monitor_id_creates_separate_incident(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        $this->postJson('/api/security/uptime-events', $this->downPayload(['monitor_id' => 'mon-1']), $this->headers())
            ->assertJson(['action' => 'down_created']);

        $this->postJson('/api/security/uptime-events', $this->downPayload(['monitor_id' => 'mon-2']), $this->headers())
            ->assertJson(['action' => 'down_created']);

        $this->assertDatabaseCount('security_incidents', 2);
    }

    public function test_down_different_occurred_at_creates_separate_incident(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        $time1 = '2026-01-01 10:00:00';
        $time2 = '2026-01-01 11:00:00';

        $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => $time1,
        ]), $this->headers())->assertJson(['action' => 'down_created']);

        $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => $time2,
        ]), $this->headers())->assertJson(['action' => 'down_created']);

        $this->assertDatabaseCount('security_incidents', 2);
    }

    // ---------- UP -> resolve insiden ----------

    public function test_up_resolves_matching_open_incident(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // Kirim DOWN dulu
        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers())
            ->assertJson(['action' => 'down_created']);

        $incident = SecurityIncident::firstOrFail();
        $this->assertSame(IncidentStatus::Open, $incident->status);

        // Kirim UP untuk monitor yang sama
        $upTime = now()->addMinutes(5)->toIso8601String();
        $response = $this->postJson('/api/security/uptime-events', $this->upPayload([
            'occurred_at' => $upTime,
        ]), $this->headers());

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'up_resolved'])
            ->assertJsonStructure(['incident_id']);

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame(
            Carbon::parse($upTime)->format('Y-m-d H:i:s'),
            $incident->resolved_at->format('Y-m-d H:i:s')
        );
    }

    public function test_up_ignored_when_no_open_incident(): void
    {
        $response = $this->postJson('/api/security/uptime-events', $this->upPayload(), $this->headers());

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'up_no_open_incident']);

        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_up_only_resolves_open_incident_not_resolved_ones(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // DOWN -> insiden dibuat
        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers())
            ->assertJson(['action' => 'down_created']);

        // UP -> resolve
        $this->postJson('/api/security/uptime-events', $this->upPayload(), $this->headers())
            ->assertJson(['action' => 'up_resolved']);

        // UP lagi -> harus diabaikan (sudah resolved)
        $response = $this->postJson('/api/security/uptime-events', $this->upPayload(), $this->headers());

        $response->assertOk()
            ->assertJson(['action' => 'up_no_open_incident']);
    }

    public function test_up_matches_by_monitor_id_prefix(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // Buat 2 insiden untuk monitor berbeda
        $response1 = $this->postJson('/api/security/uptime-events', $this->downPayload(['monitor_id' => 'mon-1']), $this->headers());
        $response1->assertJson(['action' => 'down_created']);
        
        $response2 = $this->postJson('/api/security/uptime-events', $this->downPayload(['monitor_id' => 'mon-2']), $this->headers());
        $response2->assertJson(['action' => 'down_created']);

        // UP untuk mon-1 hanya harus resolve insiden mon-1
        $this->postJson('/api/security/uptime-events', $this->upPayload(['monitor_id' => 'mon-1']), $this->headers())
            ->assertJson(['action' => 'up_resolved']);

        $incidents = SecurityIncident::where('client_id', $client->id)->get();
        $mon1 = $incidents->first(fn ($i) => str_starts_with($i->external_id, 'uptime-kuma:mon-1:'));
        $mon2 = $incidents->first(fn ($i) => str_starts_with($i->external_id, 'uptime-kuma:mon-2:'));

        $this->assertNotNull($mon1, 'Incident mon-1 should exist');
        $this->assertNotNull($mon2, 'Incident mon-2 should exist');
        $this->assertSame(IncidentStatus::Resolved, $mon1->status);
        $this->assertSame(IncidentStatus::Open, $mon2->status);
    }

    // ---------- full cycle DOWN -> UP ----------

    public function test_full_cycle_down_then_up_creates_and_resolves(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // DOWN
        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers())
            ->assertJson(['action' => 'down_created']);

        $incident = SecurityIncident::firstOrFail();
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertNull($incident->resolved_at);

        // UP
        $upTime = now()->addMinutes(10)->toIso8601String();
        $this->postJson('/api/security/uptime-events', $this->upPayload(['occurred_at' => $upTime]), $this->headers())
            ->assertJson(['action' => 'up_resolved']);

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertNotNull($incident->resolved_at);
    }

    // ---------- rate limiting ----------

    public function test_endpoint_is_rate_limited(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/security/uptime-events', $this->downPayload(['monitor_id' => "mon-{$i}"]), $this->headers())
                ->assertOk();
        }

        $this->postJson('/api/security/uptime-events', $this->downPayload(['monitor_id' => 'mon-61']), $this->headers())
            ->assertStatus(429);
    }
}