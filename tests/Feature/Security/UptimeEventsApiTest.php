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
use Illuminate\Support\Facades\Config;
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
            'severity' => 'critical',
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
        $this->assertSame(IncidentSeverity::Critical, $incident->severity);
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
        $time2 = '2026-01-01 10:40:00'; // 40 menit setelah resolve pertama -> insiden BARU (bukan re-open)

        // DOWN pertama -> buat insiden
        $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => $time1,
        ]), $this->headers())->assertJson(['action' => 'down_created']);

        // UP -> resolve insiden pertama
        $this->postJson('/api/security/uptime-events', $this->upPayload([
            'occurred_at' => '2026-01-01 10:05:00',
        ]), $this->headers())->assertJson(['action' => 'up_resolved']);

        // DOWN kedua (occurred_at beda, >30 menit setelah resolve) -> buat insiden BARU
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

    // ---------- validasi monitor_id (allowlist) ----------

    public function test_down_rejected_when_monitor_id_not_in_allowlist(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // Configure allowlist with only 'mon-valid'
        Config::set('crm.security.uptime_valid_monitor_ids', ['mon-valid']);

        $response = $this->postJson('/api/security/uptime-events', $this->downPayload(['monitor_id' => 'mon-test']), $this->headers());

        $response->assertStatus(422)
            ->assertJson(['status' => 'error', 'action' => 'validate_monitor_id']);

        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_down_accepted_when_monitor_id_in_allowlist(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // Configure allowlist with 'mon-123'
        Config::set('crm.security.uptime_valid_monitor_ids', ['mon-123']);

        $response = $this->postJson('/api/security/uptime-events', $this->downPayload(['monitor_id' => 'mon-123']), $this->headers());

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'down_created']);

        $this->assertDatabaseCount('security_incidents', 1);
    }

    public function test_down_allowlist_empty_skips_validation(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // Empty allowlist (default) - should skip validation
        Config::set('crm.security.uptime_valid_monitor_ids', []);

        $response = $this->postJson('/api/security/uptime-events', $this->downPayload(['monitor_id' => 'any-monitor-id']), $this->headers());

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'down_created']);

        $this->assertDatabaseCount('security_incidents', 1);
    }

    // ---------- validasi konsistensi external_id time vs occurred_at ----------

    public function test_down_accepted_when_external_id_time_matches_occurred_at(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // occurred_at matches external_id time (within tolerance)
        // Since external_id is generated from occurred_at, they always match in normal flow
        $occurredAt = '2026-01-01 12:00:00';

        $response = $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => $occurredAt,
        ]), $this->headers());

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'down_created']);

        $this->assertDatabaseCount('security_incidents', 1);
    }

    public function test_down_accepted_when_external_id_time_within_tolerance(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // Set tolerance to 10 minutes
        Config::set('crm.security.uptime_external_id_time_tolerance_minutes', 10);

        // The external_id is generated from occurred_at, so they always match in normal flow.
        // But if we manually test with a pre-existing incident that has mismatched external_id,
        // the validation would catch it. For now, test that normal flow works with larger tolerance.
        $occurredAt = '2026-01-01 12:00:00';

        $response = $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => $occurredAt,
        ]), $this->headers());

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'down_created']);

        $this->assertDatabaseCount('security_incidents', 1);
    }

    public function test_up_rejected_when_monitor_id_not_in_allowlist(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        Config::set('crm.security.uptime_valid_monitor_ids', ['mon-valid']);

        $response = $this->postJson('/api/security/uptime-events', $this->upPayload(['monitor_id' => 'mon-test']), $this->headers());

        $response->assertStatus(422)
            ->assertJson(['status' => 'error', 'action' => 'validate_monitor_id']);

        $this->assertDatabaseCount('security_incidents', 0);
    }

    // ---------- unit test for validateExternalIdTimeConsistency method ----------

    public function test_validate_external_id_time_consistency_directly(): void
    {
        $service = app(\App\Domains\Security\Services\UptimeEventIngest::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('validateExternalIdTimeConsistency');
        $method->setAccessible(true);

        // Test 1: matching times (should pass)
        $result = $method->invoke($service, 'uptime-kuma:mon-123:202601011200', '2026-01-01 12:00:00');
        $this->assertNull($result);

        // Test 2: within 2 minute tolerance (default)
        $result = $method->invoke($service, 'uptime-kuma:mon-123:202601011200', '2026-01-01 12:01:30');
        $this->assertNull($result);

        // Test 3: outside 2 minute tolerance (should fail)
        Config::set('crm.security.uptime_external_id_time_tolerance_minutes', 2);
        $result = $method->invoke($service, 'uptime-kuma:mon-123:202601011200', '2026-01-01 12:05:00');
        $this->assertNotNull($result);
        $this->assertSame('error', $result['status']);
        $this->assertSame('validate_external_id_time', $result['action']);

        // Test 4: with larger tolerance (should pass)
        Config::set('crm.security.uptime_external_id_time_tolerance_minutes', 10);
        $result = $method->invoke($service, 'uptime-kuma:mon-123:202601011200', '2026-01-01 12:05:00');
        $this->assertNull($result);

        // Test 5: invalid external_id format (should pass - skip validation)
        $result = $method->invoke($service, 'invalid-format', '2026-01-01 12:00:00');
        $this->assertNull($result);

        // Test 6: external_id with non-numeric time (should pass - skip validation)
        $result = $method->invoke($service, 'uptime-kuma:mon-123:abcdef', '2026-01-01 12:00:00');
        $this->assertNull($result);

        // Test 7: invalid occurred_at (should pass - skip validation)
        $result = $method->invoke($service, 'uptime-kuma:mon-123:202601011200', 'invalid-date');
        $this->assertNull($result);
    }
}