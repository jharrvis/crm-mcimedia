<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityApiTest extends TestCase
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
        ]);
    }

    /** @return array<string, string> */
    private function headers(string $token = self::TOKEN): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /** @return array<string, mixed> */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'client_id' => ClientFactory::new()->create()->id,
            'occurred_at' => now()->toIso8601String(),
            'severity' => 'high',
            'source' => 'firewall',
            'title' => 'Brute force SSH',
            'description' => 'fail2ban memblokir 320 percobaan.',
        ], $overrides);
    }

    // ---------- autentikasi ----------

    public function test_missing_token_is_rejected(): void
    {
        $this->postJson('/api/security/events', ['events' => []])->assertUnauthorized();
        $this->getJson('/api/security/status')->assertUnauthorized();
    }

    public function test_wrong_token_is_rejected(): void
    {
        $this->postJson('/api/security/events', ['events' => []], $this->headers('salah'))
            ->assertUnauthorized();
    }

    public function test_accepts_x_api_token_header(): void
    {
        $this->getJson('/api/security/status', ['X-Api-Token' => self::TOKEN])->assertOk();
    }

    public function test_endpoints_are_unavailable_when_token_not_configured(): void
    {
        config(['crm.security.api_token' => '']);

        $this->postJson('/api/security/events', ['events' => []], $this->headers())->assertStatus(503);
        $this->getJson('/api/security/status', $this->headers())->assertStatus(503);
    }

    public function test_endpoints_are_unavailable_when_api_disabled(): void
    {
        config(['crm.security.api_enabled' => false]);

        $this->getJson('/api/security/status', $this->headers())->assertStatus(503);
    }

    // ---------- ingest ----------

    public function test_ingests_batch_of_events(): void
    {
        $response = $this->postJson('/api/security/events', [
            'events' => [
                $this->event(['external_id' => 'ext-1']),
                $this->event(['external_id' => 'ext-2', 'severity' => 'critical', 'source' => 'wpscan']),
            ],
        ], $this->headers());

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'received' => 2, 'created' => 2, 'duplicates' => 0, 'errors' => []]);

        $this->assertDatabaseCount('security_incidents', 2);
        $this->assertDatabaseHas('security_incidents', [
            'external_id' => 'ext-2',
            'severity' => 'critical',
            'source' => 'wpscan',
            'status' => 'open',
        ]);
    }

    public function test_external_id_makes_ingest_idempotent(): void
    {
        $event = $this->event(['external_id' => 'stable-key-1']);

        $this->postJson('/api/security/events', ['events' => [$event]], $this->headers())
            ->assertJson(['created' => 1, 'duplicates' => 0]);

        $this->postJson('/api/security/events', ['events' => [$event]], $this->headers())
            ->assertJson(['created' => 0, 'duplicates' => 1]);

        $this->assertDatabaseCount('security_incidents', 1);
    }

    public function test_events_without_external_id_use_dedup_window(): void
    {
        $event = $this->event();

        $this->postJson('/api/security/events', ['events' => [$event]], $this->headers())
            ->assertJson(['created' => 1]);

        // Temuan identik (klien+sumber+judul) dalam jendela dedup = duplikat.
        $this->postJson('/api/security/events', ['events' => [$event]], $this->headers())
            ->assertJson(['created' => 0, 'duplicates' => 1]);

        $this->assertDatabaseCount('security_incidents', 1);
    }

    public function test_dedup_window_expiry_allows_new_incident(): void
    {
        config(['crm.security.dedup_window_minutes' => 60]);

        $this->postJson('/api/security/events', ['events' => [$this->event()]], $this->headers())
            ->assertJson(['created' => 1]);

        $this->travel(2)->hours();

        $this->postJson('/api/security/events', ['events' => [$this->event()]], $this->headers())
            ->assertJson(['created' => 1, 'duplicates' => 0]);
    }

    public function test_invalid_events_are_reported_without_failing_whole_batch(): void
    {
        $response = $this->postJson('/api/security/events', [
            'events' => [
                $this->event(['external_id' => 'ok-1']),
                $this->event(['external_id' => 'bad-1', 'severity' => 'pancake']),
            ],
        ], $this->headers());

        $response->assertOk()->assertJson(['created' => 1, 'duplicates' => 0]);
        $this->assertCount(1, $response->json('errors'));
        $this->assertSame(1, $response->json('errors.0.index'));

        $this->assertDatabaseCount('security_incidents', 1);
    }

    public function test_events_payload_is_required(): void
    {
        $this->postJson('/api/security/events', [], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('events');

        $this->postJson('/api/security/events', ['events' => []], $this->headers())
            ->assertStatus(422);
    }

    public function test_unknown_client_is_rejected_per_event(): void
    {
        $response = $this->postJson('/api/security/events', [
            'events' => [$this->event(['client_id' => 999999, 'external_id' => 'nope'])],
        ], $this->headers());

        $response->assertOk()->assertJson(['created' => 0]);
        $this->assertCount(1, $response->json('errors'));
        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_api_never_stores_extra_credential_fields(): void
    {
        $client = ClientFactory::new()->create();

        $this->postJson('/api/security/events', [
            'events' => [[
                'client_id' => $client->id,
                'external_id' => 'cred-test',
                'occurred_at' => now()->toIso8601String(),
                'severity' => 'info',
                'source' => 'monitor',
                'title' => 'Uji kredensial',
                // Field berbahaya yang harus diabaikan total oleh API.
                'server_password' => 'hunter2-super-secret',
                'ssh_private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            ]],
        ], $this->headers())->assertJson(['created' => 1]);

        $incident = SecurityIncident::firstOrFail();
        $this->assertArrayNotHasKey('server_password', $incident->getAttributes());
        $this->assertSame('Uji kredensial', $incident->title);
        $this->assertSame(IncidentSeverity::Info, $incident->severity);
        $this->assertSame(IncidentSource::Monitor, $incident->source);
        $this->assertSame(IncidentStatus::Open, $incident->status);
    }

    public function test_status_endpoint_reports_health_and_counts(): void
    {
        ClientFactory::new()->create();
        SecurityIncident::factory()->create();

        $response = $this->getJson('/api/security/status', $this->headers());

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'service' => 'crm-security-api',
            'counts' => ['incidents_total' => 1, 'incidents_open' => 1],
        ]);
        $response->assertJsonStructure(['status', 'service', 'time', 'counts']);
    }

    public function test_events_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/security/events', ['events' => [$this->event(['external_id' => "rl-{$i}"])]], $this->headers())
                ->assertOk();
        }

        $this->postJson('/api/security/events', ['events' => [$this->event(['external_id' => 'rl-61'])]], $this->headers())
            ->assertStatus(429);
    }
}
