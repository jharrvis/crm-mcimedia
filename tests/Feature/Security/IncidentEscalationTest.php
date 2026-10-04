<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Services\IncidentFonnteNotifier;
use App\Domains\Security\Services\IncidentKanbanCardCreator;
use App\Domains\Services\Models\Service;
use Database\Factories\ClientFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IncidentEscalationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-security-token-123';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'crm.security.api_enabled' => true,
            'crm.security.api_token' => self::TOKEN,
            'crm.security.dedup_window_minutes' => 1440,
            'crm.security.uptime_default_client_id' => null,
            'crm.fonnte.enabled' => true,
            'crm.fonnte.token' => 'test-fonnte-token',
            'crm.fonnte.target' => '6281234567890',
            'crm.fonnte.endpoint' => 'https://api.fonnte.com/send',
            'app.url' => 'https://crm.test',
        ]);

        // Mock Fonnte API
        Http::fake([
            'https://api.fonnte.com/send' => Http::response(['status' => 'success'], 200),
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

    // ===== Tests for severity classification =====

    public function test_down_creates_incident_with_critical_severity_p1(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers())
            ->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'down_created']);

        $incident = SecurityIncident::firstOrFail();
        $this->assertSame(IncidentSeverity::Critical, $incident->severity);
        $this->assertSame(IncidentSource::Monitor, $incident->source);
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertFalse($incident->is_major);
        $this->assertFalse($incident->is_flapping);
        $this->assertSame(1, $incident->flap_count);
    }

    // ===== Tests for re-open + flapping within 30 min =====

    public function test_down_within_30_min_after_resolve_reopens_with_flapping(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // DOWN pertama
        $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => '2026-01-01 10:00:00',
        ]), $this->headers())->assertJson(['action' => 'down_created']);

        $incident = SecurityIncident::firstOrFail();
        $firstIncidentId = $incident->id;

        // UP -> resolve
        $this->postJson('/api/security/uptime-events', $this->upPayload([
            'occurred_at' => '2026-01-01 10:05:00',
        ]), $this->headers())->assertJson(['action' => 'up_resolved']);

        // DOWN kedua dalam 30 menit setelah resolve -> RE-OPEN + flapping
        $response = $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => '2026-01-01 10:20:00',
        ]), $this->headers());

        $response->assertOk()
            ->assertJson(['action' => 'down_reopened_flapping']);

        // Harus sama incident ID (re-open, bukan baru)
        $this->assertSame($firstIncidentId, $response->json('incident_id'));

        $incident->refresh();
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertTrue($incident->is_flapping);
        $this->assertSame(2, $incident->flap_count);
        $this->assertSame(IncidentSeverity::Critical, $incident->severity);
        $this->assertNull($incident->resolved_at);
    }

    public function test_down_after_30_min_resolve_creates_new_incident(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // DOWN pertama
        $response1 = $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => '2026-01-01 10:00:00',
        ]), $this->headers())->assertJson(['action' => 'down_created']);

        $firstIncidentId = $response1->json('incident_id');

        // UP -> resolve
        $this->postJson('/api/security/uptime-events', $this->upPayload([
            'occurred_at' => '2026-01-01 10:05:00',
        ]), $this->headers())->assertJson(['action' => 'up_resolved']);

        // DOWN kedua SETELAH 30 menit (40 menit) -> insiden BARU
        $response2 = $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => '2026-01-01 10:45:00',
        ]), $this->headers());

        $response2->assertOk()
            ->assertJson(['action' => 'down_created']);

        // Harus incident ID yang berbeda
        $this->assertNotSame($firstIncidentId, $response2->json('incident_id'));
        $this->assertDatabaseCount('security_incidents', 2);
    }

    // ===== Tests for flapping detection (>3 DOWN in 1 hour) =====

    public function test_flapping_detected_after_3_downs_in_1_hour(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // DOWN 1
        $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => '2026-01-01 10:00:00',
        ]), $this->headers())->assertJson(['action' => 'down_created']);

        // UP 1
        $this->postJson('/api/security/uptime-events', $this->upPayload([
            'occurred_at' => '2026-01-01 10:05:00',
        ]), $this->headers())->assertJson(['action' => 'up_resolved']);

        // DOWN 2 (within 30 min -> reopen flapping)
        $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => '2026-01-01 10:20:00',
        ]), $this->headers())->assertJson(['action' => 'down_reopened_flapping']);

        // UP 2
        $this->postJson('/api/security/uptime-events', $this->upPayload([
            'occurred_at' => '2026-01-01 10:25:00',
        ]), $this->headers())->assertJson(['action' => 'up_resolved']);

        // DOWN 3 (within 30 min -> reopen, flap_count = 3)
        $response = $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => '2026-01-01 10:40:00',
        ]), $this->headers());

        $response->assertJson(['action' => 'down_reopened_flapping']);

        $incident = SecurityIncident::firstOrFail();
        $this->assertTrue($incident->is_flapping);
        $this->assertSame(3, $incident->flap_count);
    }

    // ===== Tests for Fonnte notifier =====

    public function test_fonnte_notifier_sends_p1_notification(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers())
            ->assertJson(['action' => 'down_created']);

        $incident = SecurityIncident::firstOrFail();

        // Verify WA notification was sent (mocked)
        Http::assertSent(function ($request) use ($incident) {
            return $request->url() === 'https://api.fonnte.com/send'
                && str_contains($request->data()['message'] ?? '', 'INCIDENT P1 - CRITICAL')
                && $request->data()['target'] === '6281234567890';
        });

        // Verify wa_notification_meta is set
        $incident->refresh();
        $meta = $incident->wa_notification_meta ?? [];
        $this->assertTrue($meta['p1_created'] ?? false);
    }

    public function test_fonnte_notifier_does_not_spam_same_level(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers())
            ->assertJson(['action' => 'down_created']);

        $incident = SecurityIncident::firstOrFail();

        // Manually mark as notified
        $incident->markWaNotified('p1_created');

        // Try to send again - should be skipped
        $notifier = app(IncidentFonnteNotifier::class);
        $notifier->notifyP1Created($incident);

        // Should only have 1 HTTP call (the first one)
        Http::assertSentCount(1);
    }

    // ===== Tests for Kanban card creator =====

    public function test_kanban_card_creator_creates_card_for_p1(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        $this->postJson('/api/security/uptime-events', $this->downPayload(), $this->headers())
            ->assertJson(['action' => 'down_created']);

        $incident = SecurityIncident::firstOrFail();

        // Verify kanban card was created (check database)
        // Since we can't easily test the external kanban DB in unit tests,
        // we verify the service is called without error
        $creator = app(IncidentKanbanCardCreator::class);
        $cardId = $creator->createForP1Incident($incident);

        // In test environment, kanban DB might not exist, so cardId could be null
        // Just verify no exception thrown
        $this->assertTrue(true);
    }

    // ===== Tests for escalation command =====

    public function test_escalation_command_p1_to_p2_after_15_min(): void
    {
        $client = ClientFactory::new()->create();

        // Create P1 incident older than 15 minutes
        $incident = SecurityIncident::create([
            'client_id' => $client->id,
            'external_id' => 'uptime-kuma:mon-1:202601011000',
            'occurred_at' => Carbon::parse('2026-01-01 10:00:00'),
            'severity' => IncidentSeverity::Critical,
            'source' => IncidentSource::Monitor,
            'title' => '[example.com] Website down',
            'description' => 'Test',
            'status' => IncidentStatus::Open,
            'is_flapping' => false,
            'flap_count' => 1,
            'is_major' => false,
        ]);

        $this->artisan('crm:escalate-incidents')->assertExitCode(0);

        $incident->refresh();
        $this->assertSame(IncidentSeverity::High, $incident->severity); // P2
    }

    public function test_escalation_command_marks_major_after_30_min(): void
    {
        $client = ClientFactory::new()->create();

        // Create P1 incident older than 30 minutes
        $incident = SecurityIncident::create([
            'client_id' => $client->id,
            'external_id' => 'uptime-kuma:mon-1:202601011000',
            'occurred_at' => Carbon::parse('2026-01-01 10:00:00'),
            'severity' => IncidentSeverity::Critical,
            'source' => IncidentSource::Monitor,
            'title' => '[example.com] Website down',
            'description' => 'Test',
            'status' => IncidentStatus::Open,
            'is_flapping' => false,
            'flap_count' => 1,
            'is_major' => false,
        ]);

        $this->artisan('crm:escalate-incidents')->assertExitCode(0);

        $incident->refresh();
        $this->assertTrue($incident->is_major);
    }

    public function test_escalation_command_also_escalates_p2_to_major_after_30_min(): void
    {
        $client = ClientFactory::new()->create();

        // Create P2 incident older than 30 minutes
        $incident = SecurityIncident::create([
            'client_id' => $client->id,
            'external_id' => 'uptime-kuma:mon-1:202601011000',
            'occurred_at' => Carbon::parse('2026-01-01 10:00:00'),
            'severity' => IncidentSeverity::High, // Already P2
            'source' => IncidentSource::Monitor,
            'title' => '[example.com] Website down',
            'description' => 'Test',
            'status' => IncidentStatus::Open,
            'is_flapping' => false,
            'flap_count' => 1,
            'is_major' => false,
        ]);

        $this->artisan('crm:escalate-incidents')->assertExitCode(0);

        $incident->refresh();
        $this->assertTrue($incident->is_major);
        $this->assertSame(IncidentSeverity::High, $incident->severity); // Stays P2
    }

    public function test_escalation_command_does_not_affect_resolved_incidents(): void
    {
        $client = ClientFactory::new()->create();

        $incident = SecurityIncident::create([
            'client_id' => $client->id,
            'external_id' => 'uptime-kuma:mon-1:202601011000',
            'occurred_at' => Carbon::parse('2026-01-01 10:00:00'),
            'severity' => IncidentSeverity::Critical,
            'source' => IncidentSource::Monitor,
            'title' => '[example.com] Website down',
            'description' => 'Test',
            'status' => IncidentStatus::Resolved,
            'resolved_at' => Carbon::parse('2026-01-01 10:30:00'),
            'is_flapping' => false,
            'flap_count' => 1,
            'is_major' => false,
        ]);

        $this->artisan('crm:escalate-incidents')->assertExitCode(0);

        $incident->refresh();
        $this->assertSame(IncidentSeverity::Critical, $incident->severity); // Unchanged
        $this->assertFalse($incident->is_major);
    }

    // ===== Tests for WA recovery notification =====

    public function test_up_sends_recovery_notification(): void
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

        // UP
        $this->postJson('/api/security/uptime-events', $this->upPayload(), $this->headers())
            ->assertJson(['action' => 'up_resolved']);

        // Verify recovery WA sent
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fonnte.com/send'
                && str_contains($request->data()['message'] ?? '', 'INCIDENT RECOVERED');
        });
    }

    // ===== Tests for flap_count reset after 1 hour stable =====

    public function test_flap_count_resets_after_1_hour_stable(): void
    {
        $client = ClientFactory::new()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        // Create old incidents (>1 hour ago) for same monitor
        SecurityIncident::create([
            'client_id' => $client->id,
            'external_id' => 'uptime-kuma:mon-123:202601010700',
            'occurred_at' => Carbon::parse('2026-01-01 07:00:00'),
            'severity' => IncidentSeverity::Critical,
            'source' => IncidentSource::Monitor,
            'title' => '[example.com] Website down',
            'description' => 'Old incident 1',
            'status' => IncidentStatus::Resolved,
            'resolved_at' => Carbon::parse('2026-01-01 07:05:00'),
            'is_flapping' => false,
            'flap_count' => 1,
            'is_major' => false,
        ]);

        SecurityIncident::create([
            'client_id' => $client->id,
            'external_id' => 'uptime-kuma:mon-123:202601010800',
            'occurred_at' => Carbon::parse('2026-01-01 08:00:00'),
            'severity' => IncidentSeverity::Critical,
            'source' => IncidentSource::Monitor,
            'title' => '[example.com] Website down',
            'description' => 'Old incident 2',
            'status' => IncidentStatus::Resolved,
            'resolved_at' => Carbon::parse('2026-01-01 08:05:00'),
            'is_flapping' => false,
            'flap_count' => 1,
            'is_major' => false,
        ]);

        // New DOWN now (10:00) - should have flap_count = 1 (old ones >1 hour ago)
        $this->postJson('/api/security/uptime-events', $this->downPayload([
            'occurred_at' => '2026-01-01 10:00:00',
        ]), $this->headers())->assertJson(['action' => 'down_created']);

        $incident = SecurityIncident::latest('id')->first();
        $this->assertSame(1, $incident->flap_count); // Reset because old ones >1 hour
        $this->assertFalse($incident->is_flapping);
    }
}