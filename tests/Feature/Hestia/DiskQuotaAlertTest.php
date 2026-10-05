<?php

namespace Tests\Feature\Hestia;

use App\Domains\Hestia\Enums\DiskAlertLevel;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Alert kuota disk website (t_afef420a): 80% warning, 90% kritis.
 *
 * Cakupan: ambang & severity insiden, idempotensi (tidak ada duplikat saat
 * level tidak berubah), eskalasi warning -> critical, recovery, akun tanpa
 * kuota/tanpa data dilewati, fallback klien untuk akun belum terpetakan, dan
 * pengiriman notifikasi WA (anti-spam per level).
 */
class DiskQuotaAlertTest extends TestCase
{
    use RefreshDatabase;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'crm.disk_quota.warning_percent' => 80,
            'crm.disk_quota.critical_percent' => 90,
            'crm.disk_quota.default_client_id' => null,
            'crm.fonnte.enabled' => true,
            'crm.fonnte.token' => 'test-fonnte-token',
            'crm.fonnte.target' => '6281234567890',
            'crm.fonnte.endpoint' => 'https://api.fonnte.com/send',
            'app.url' => 'https://crm.test',
        ]);

        Http::fake([
            'https://api.fonnte.com/send' => Http::response(['status' => 'success'], 200),
        ]);

        $this->clientId = ClientFactory::new()->create()->id;
    }

    /**
     * Buat akun Hestia dengan kuota 1000 MB dan pemakaian tertentu.
     */
    private function account(int $usedMb, ?int $quotaMb = 1000, array $overrides = []): HestiaAccount
    {
        return HestiaAccount::create(array_merge([
            'external_key' => HestiaAccount::keyFor('mcimedia', 'contoh'.uniqid()).uniqid(),
            'hestia_user' => 'mcimedia',
            'domain' => 'contoh'.uniqid().'.com',
            'plan' => 'default',
            'service_type' => 'hosting',
            'status' => 'active',
            'mapping_status' => 'mapped',
            'client_id' => $this->clientId,
            'disk_used' => $usedMb,
            'disk_quota' => $quotaMb,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $overrides));
    }

    private function runCommand(): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('crm:disk-quota-alerts');
    }

    // ---------- ambang & severity ----------

    public function test_below_warning_creates_no_alert(): void
    {
        $this->account(790); // 79%

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(0, SecurityIncident::count());
        $this->assertSame(DiskAlertLevel::None, $this->account(0)->alertLevel());
    }

    public function test_at_80_percent_creates_warning_incident(): void
    {
        $account = $this->account(800); // tepat 80%

        $this->runCommand()->assertExitCode(0);

        $incident = SecurityIncident::query()->sole();
        $this->assertSame($this->clientId, $incident->client_id);
        $this->assertSame(IncidentSeverity::Medium, $incident->severity);
        $this->assertSame(IncidentSource::Monitor, $incident->source);
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertSame('disk-quota:'.$account->id.':warning', $incident->external_id);
        $this->assertStringContainsString($account->domain, $incident->title);
        $this->assertStringContainsString('80%', $incident->title);

        $this->assertSame(DiskAlertLevel::Warning, $account->fresh()->alertLevel());
        $this->assertNotNull($account->fresh()->disk_alert_at);
    }

    public function test_at_90_percent_creates_critical_incident(): void
    {
        $account = $this->account(900); // tepat 90%

        $this->runCommand()->assertExitCode(0);

        $incident = SecurityIncident::query()->sole();
        $this->assertSame(IncidentSeverity::High, $incident->severity);
        $this->assertSame('disk-quota:'.$account->id.':critical', $incident->external_id);
        $this->assertSame(DiskAlertLevel::Critical, $account->fresh()->alertLevel());
    }

    public function test_over_100_percent_is_critical(): void
    {
        $account = $this->account(1500); // 150%

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(1, SecurityIncident::count());
        $this->assertSame(IncidentSeverity::High, SecurityIncident::query()->sole()->severity);
        $this->assertSame(DiskAlertLevel::Critical, $account->fresh()->alertLevel());
    }

    // ---------- idempotensi ----------

    public function test_second_run_at_same_level_does_not_duplicate(): void
    {
        $this->account(850); // 85% -> warning

        $this->runCommand()->assertExitCode(0);
        $this->runCommand()->assertExitCode(0);

        $this->assertSame(1, SecurityIncident::count());

        // WA hanya sekali untuk level warning (anti-spam level notifier).
        Http::assertSentCount(1);
    }

    public function test_escalation_from_warning_to_critical_creates_new_incident_and_resolves_old(): void
    {
        $account = $this->account(850); // warning dulu

        $this->runCommand()->assertExitCode(0);
        $warningIncident = SecurityIncident::query()->sole();

        // Pemakaian naik melewati ambang kritis.
        $account->update(['disk_used' => 950]);

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(2, SecurityIncident::count());

        // Insiden warning lama ditutup otomatis.
        $this->assertSame(IncidentStatus::Resolved, $warningIncident->fresh()->status);
        $this->assertNotNull($warningIncident->fresh()->resolved_at);

        $critical = SecurityIncident::query()
            ->where('external_id', 'disk-quota:'.$account->id.':critical')
            ->sole();
        $this->assertSame(IncidentSeverity::High, $critical->severity);
        $this->assertSame(IncidentStatus::Open, $critical->status);
        $this->assertSame(DiskAlertLevel::Critical, $account->fresh()->alertLevel());
    }

    public function test_recovery_below_warning_resolves_open_incident(): void
    {
        $account = $this->account(850); // warning

        $this->runCommand()->assertExitCode(0);
        $incident = SecurityIncident::query()->sole();

        // Klien membersihkan file — pemakaian turun di bawah ambang.
        $account->update(['disk_used' => 500]);

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(IncidentStatus::Resolved, $incident->fresh()->status);
        $this->assertSame(DiskAlertLevel::None, $account->fresh()->alertLevel());
        // Tidak ada insiden baru.
        $this->assertSame(1, SecurityIncident::count());
    }

    // ---------- akun yang tidak bisa dihitung ----------

    public function test_unlimited_quota_is_skipped(): void
    {
        $account = $this->account(999999, 0); // kuota 0 = tanpa batas

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(0, SecurityIncident::count());
        $this->assertSame(DiskAlertLevel::None, $account->fresh()->alertLevel());
    }

    public function test_null_quota_is_skipped(): void
    {
        $account = $this->account(900, null); // Hestia belum melaporkan

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(0, SecurityIncident::count());
        $this->assertSame(DiskAlertLevel::None, $account->fresh()->alertLevel());
    }

    // ---------- resolusi klien ----------

    public function test_unmapped_account_without_fallback_is_skipped(): void
    {
        $account = $this->account(950, 1000, ['client_id' => null, 'mapping_status' => 'unmapped']);

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(0, SecurityIncident::count());
        // Level TIDAK berubah: alert gagal dibuat karena tidak ada klien tujuan.
        $this->assertSame(DiskAlertLevel::None, $account->fresh()->alertLevel());
    }

    public function test_unmapped_account_uses_fallback_client(): void
    {
        Config::set('crm.disk_quota.default_client_id', $this->clientId);

        $account = $this->account(950, 1000, ['client_id' => null, 'mapping_status' => 'unmapped']);

        $this->runCommand()->assertExitCode(0);

        $incident = SecurityIncident::query()->sole();
        $this->assertSame($this->clientId, $incident->client_id);
        $this->assertSame(DiskAlertLevel::Critical, $account->fresh()->alertLevel());
    }

    // ---------- notifikasi WA ----------

    public function test_wa_notification_is_sent_with_quota_details(): void
    {
        $account = $this->account(850);

        $this->runCommand()->assertExitCode(0);

        Http::assertSent(function ($request) use ($account): bool {
            return $request->url() === 'https://api.fonnte.com/send'
                && $request['target'] === '6281234567890'
                && str_contains($request['message'], 'ALERT KUOTA DISK')
                && str_contains($request['message'], $account->domain)
                && str_contains($request['message'], '85%');
        });
    }

    public function test_wa_not_sent_when_fonnte_disabled(): void
    {
        Config::set('crm.fonnte.enabled', false);

        $this->account(850);

        $this->runCommand()->assertExitCode(0);

        Http::assertNothingSent();
        // Insiden tetap dibuat walau WA mati.
        $this->assertSame(1, SecurityIncident::count());
    }

    // ---------- ambang dapat dikonfigurasi ----------

    public function test_thresholds_are_configurable(): void
    {
        Config::set([
            'crm.disk_quota.warning_percent' => 50,
            'crm.disk_quota.critical_percent' => 70,
        ]);

        $account = $this->account(550); // 55% -> warning pada config ini

        $this->runCommand()->assertExitCode(0);

        $incident = SecurityIncident::query()->sole();
        $this->assertSame(IncidentSeverity::Medium, $incident->severity);
        $this->assertSame(DiskAlertLevel::Warning, $account->fresh()->alertLevel());
    }

    public function test_model_current_alert_level_returns_null_without_quota(): void
    {
        $unlimited = $this->account(900, 0);
        $this->assertNull($unlimited->currentAlertLevel());

        $noUsed = $this->account(0, 1000, ['disk_used' => null]);
        $this->assertNull($noUsed->currentAlertLevel());

        $warning = $this->account(850);
        $this->assertSame(DiskAlertLevel::Warning, $warning->currentAlertLevel());

        $critical = $this->account(950);
        $this->assertSame(DiskAlertLevel::Critical, $critical->currentAlertLevel());
    }
}