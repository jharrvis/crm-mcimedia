<?php

namespace Tests\Feature\Security;

use App\Domains\Clients\Models\Client;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Enums\WpScanSiteStatus;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Models\WpScanSite;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * WPScan otomatis untuk situs WordPress klien (t_2e555b0b).
 *
 * Cakupan: penemuan target dari akun Hestia terpetakan, deteksi WordPress,
 * pembuatan insiden idempoten (external_id `wpscan:{site_id}:{fingerprint}`),
 * penutupan otomatis temuan yang hilang, pemetaan severity, penanganan error
 * binary, dan notifikasi WA untuk temuan serius.
 */
class WpScanServiceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'crm.wpscan.binary' => 'wpscan',
            'crm.wpscan.api_token' => '',
            'crm.wpscan.timeout' => 300,
            'crm.wpscan.probe_timeout' => 5,
            'crm.wpscan.verify_ssl' => false,
            'crm.fonnte.enabled' => true,
            'crm.fonnte.token' => 'test-fonnte-token',
            'crm.fonnte.target' => '6281234567890',
            'crm.fonnte.endpoint' => 'https://api.fonnte.com/send',
            'app.url' => 'https://crm.test',
        ]);

        Http::fake([
            'https://api.fonnte.com/send' => Http::response(['status' => 'success'], 200),
        ]);

        $this->client = ClientFactory::new()->create(['is_active' => true]);
    }

    /**
     * Buat akun Hestia aktif yang sudah terpetakan ke klien tes.
     */
    private function mappedAccount(string $domain, array $overrides = []): HestiaAccount
    {
        return HestiaAccount::create(array_merge([
            'external_key' => HestiaAccount::keyFor('mcimedia', $domain).uniqid(),
            'hestia_user' => 'mcimedia',
            'domain' => $domain,
            'plan' => 'default',
            'service_type' => 'hosting',
            'status' => 'active',
            'mapping_status' => 'mapped',
            'client_id' => $this->client->id,
            'suspended' => false,
            'user_suspended' => false,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $overrides));
    }

    /** Fake probe deteksi: wp-login.php menjawab 200 dengan marker WordPress. */
    private function fakeWpDetection(): void
    {
        Http::fake([
            '*/wp-login.php' => Http::response('<html><link href="/wp-content/themes/x/style.css"></html>', 200),
            'https://api.fonnte.com/send' => Http::response(['status' => 'success'], 200),
        ]);
    }

    /** Fake probe deteksi: homepage tanpa marker WordPress. */
    private function fakeNonWpDetection(): void
    {
        Http::fake([
            '*/wp-login.php' => Http::response('Not Found', 404),
            '*/*' => Http::response('<html>Halaman biasa tanpa CMS</html>', 200),
            'https://api.fonnte.com/send' => Http::response(['status' => 'success'], 200),
        ]);
    }

    /** Fake proses wpscan: JSON dengan temuan inti + plugin. */
    private function fakeWpscanOutput(): string
    {
        return json_encode([
            'version' => [
                'number' => '6.4.2',
                'vulnerabilities' => [
                    [
                        'title' => 'WordPress 6.4.2 - SQL Injection via wp_query',
                        'references' => ['url' => ['https://wpscan.com/vulnerability/abc-123']],
                    ],
                ],
            ],
            'plugins' => [
                'contact-form-7' => [
                    'version' => '5.8.0',
                    'vulnerabilities' => [
                        [
                            'title' => 'Contact Form 7 < 5.8.1 - Cross-Site Scripting (XSS)',
                            'references' => ['url' => ['https://wpscan.com/vulnerability/def-456']],
                        ],
                    ],
                ],
            ],
            'themes' => [],
        ]);
    }

    private function fakeWpscanProcess(): void
    {
        Process::fake(function ($process, $callback) {
            return Process::result(output: $this->fakeWpscanOutput(), exitCode: 1);
        });
    }

    // ---------- penemuan target ----------

    public function test_mapped_active_account_is_discovered_as_scan_target(): void
    {
        $this->fakeWpDetection();
        Process::fake();

        $this->mappedAccount('klienwp.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $site = WpScanSite::query()->sole();
        $this->assertSame('klienwp.com', $site->domain);
        $this->assertSame('https://klienwp.com', $site->url);
        $this->assertSame($this->client->id, $site->client_id);
        $this->assertSame(WpScanSiteStatus::Active, $site->status);
    }

    public function test_unmapped_account_is_not_discovered(): void
    {
        Process::fake();

        $this->mappedAccount('belum-peta.com', [
            'mapping_status' => 'unmapped',
            'client_id' => null,
        ]);

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $this->assertSame(0, WpScanSite::count());
    }

    public function test_suspended_account_is_not_discovered(): void
    {
        Process::fake();

        $this->mappedAccount('suspend.com', ['suspended' => true]);

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $this->assertSame(0, WpScanSite::count());
    }

    public function test_inactive_client_account_is_not_discovered(): void
    {
        Process::fake();

        $this->client->update(['is_active' => false]);
        $this->mappedAccount('klien-nonaktif.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $this->assertSame(0, WpScanSite::count());
    }

    public function test_duplicate_domain_shares_single_site_row(): void
    {
        $this->fakeWpDetection();
        Process::fake();

        $this->mappedAccount('sama.com');
        $this->mappedAccount('sama.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $this->assertSame(1, WpScanSite::count());
    }

    // ---------- deteksi WordPress ----------

    public function test_non_wordpress_site_is_marked_and_skipped(): void
    {
        $this->fakeNonWpDetection();
        Process::fake();

        $this->mappedAccount('bukanwp.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $site = WpScanSite::query()->sole();
        $this->assertSame(WpScanSiteStatus::NonWordpress, $site->status);
        Process::assertNotRan(fn ($process) => str_contains($process->command, 'bukanwp.com'));
    }

    public function test_second_run_does_not_rescan_non_wordpress_site(): void
    {
        $this->fakeNonWpDetection();
        Process::fake();

        $this->mappedAccount('bukanwp.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);
        $this->artisan('crm:wpscan')->assertExitCode(0);

        // Situs non_wordpress tidak lagi masuk antrean scan maupun deteksi.
        $this->assertSame(WpScanSiteStatus::NonWordpress, WpScanSite::query()->sole()->status);
    }

    // ---------- scan & insiden ----------

    public function test_scan_creates_incidents_with_idempotent_external_ids(): void
    {
        $this->fakeWpDetection();
        $this->fakeWpscanProcess();

        $this->mappedAccount('klienwp.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $site = WpScanSite::query()->sole();
        $this->assertSame(WpScanSiteStatus::Active, $site->status);
        $this->assertSame('6.4.2', $site->wp_version);
        $this->assertSame(2, $site->last_finding_count);
        $this->assertNotNull($site->last_scan_at);

        $incidents = SecurityIncident::query()
            ->where('source', IncidentSource::Wpscan)
            ->get();

        $this->assertSame(2, $incidents->count());

        foreach ($incidents as $incident) {
            $this->assertSame($this->client->id, $incident->client_id);
            $this->assertSame(IncidentStatus::Open, $incident->status);
            $this->assertStringStartsWith('wpscan:'.$site->id.':', (string) $incident->external_id);
            $this->assertStringContainsString('klienwp.com', $incident->title);
        }

        // Kerentanan SQLi inti -> critical; XSS plugin -> medium.
        $this->assertTrue($incidents->contains(fn ($i) => $i->severity === IncidentSeverity::Critical));
        $this->assertTrue($incidents->contains(fn ($i) => $i->severity === IncidentSeverity::Medium));
    }

    public function test_second_scan_does_not_duplicate_incidents(): void
    {
        $this->fakeWpDetection();
        $this->fakeWpscanProcess();

        $this->mappedAccount('klienwp.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);
        $this->artisan('crm:wpscan')->assertExitCode(0);

        $this->assertSame(2, SecurityIncident::count());
    }

    public function test_finding_absent_in_later_scan_is_resolved(): void
    {
        $this->fakeWpDetection();
        $this->fakeWpscanProcess();

        $this->mappedAccount('klienwp.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);
        $this->assertSame(2, SecurityIncident::open()->count());

        // Scan berikutnya: hanya temuan inti yang tersisa (plugin sudah diupdate).
        Process::fake(function ($process, $callback) {
            return Process::result(output: json_encode([
                'version' => [
                    'number' => '6.4.3',
                    'vulnerabilities' => [
                        ['title' => 'WordPress 6.4.2 - SQL Injection via wp_query'],
                    ],
                ],
                'plugins' => [],
                'themes' => [],
            ]), exitCode: 1);
        });

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $this->assertSame(2, SecurityIncident::count());
        $this->assertSame(1, SecurityIncident::open()->count());

        $resolved = SecurityIncident::query()
            ->where('status', IncidentStatus::Resolved)
            ->sole();
        $this->assertStringContainsString('Contact Form 7', $resolved->title);
        $this->assertNotNull($resolved->resolved_at);
    }

    // ---------- penanganan error ----------

    public function test_missing_binary_marks_site_error_without_incidents(): void
    {
        $this->fakeWpDetection();
        Process::fake(); // tidak ada proses wpscan yang dijalankan

        Config::set('crm.wpscan.binary', '/nonexistent/path/wpscan');

        $this->mappedAccount('klienwp.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $site = WpScanSite::query()->sole();
        $this->assertSame(WpScanSiteStatus::Error, $site->status);
        $this->assertStringContainsString('tidak ditemukan', (string) $site->last_error);
        $this->assertSame(0, SecurityIncident::count());
    }

    public function test_malformed_json_marks_site_error(): void
    {
        $this->fakeWpDetection();
        Process::fake(function ($process, $callback) {
            return Process::result(output: 'ini bukan json sama sekali', exitCode: 1);
        });

        $this->mappedAccount('klienwp.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $site = WpScanSite::query()->sole();
        $this->assertSame(WpScanSiteStatus::Error, $site->status);
        $this->assertNotEmpty($site->last_error);
        $this->assertSame(0, SecurityIncident::count());
    }

    public function test_error_site_is_retried_on_next_run(): void
    {
        $this->fakeWpDetection();
        Process::fake(function ($process, $callback) {
            return Process::result(output: 'rusak', exitCode: 1);
        });

        $this->mappedAccount('klienwp.com');
        $this->artisan('crm:wpscan')->assertExitCode(0);
        $this->assertSame(WpScanSiteStatus::Error, WpScanSite::query()->sole()->status);

        // Run kedua dengan output valid -> pulih jadi active.
        $this->fakeWpscanProcess();
        $this->artisan('crm:wpscan')->assertExitCode(0);

        $site = WpScanSite::query()->sole();
        $this->assertSame(WpScanSiteStatus::Active, $site->status);
        $this->assertNull($site->last_error);
        $this->assertSame(2, SecurityIncident::count());
    }

    // ---------- notifikasi WA ----------

    public function test_wa_sent_only_for_high_or_critical_findings(): void
    {
        $this->fakeWpDetection();
        $this->fakeWpscanProcess();

        $this->mappedAccount('klienwp.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);

        // 2 temuan, hanya 1 yang critical (SQLi inti) -> 1 WA.
        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fonnte.com/send'
                && str_contains($request['message'], 'TEMUAN WPSCAN')
                && str_contains($request['message'], 'klienwp.com');
        });
    }

    public function test_wa_not_sent_when_fonnte_disabled(): void
    {
        Config::set('crm.fonnte.enabled', false);

        $this->fakeWpDetection();
        $this->fakeWpscanProcess();

        $this->mappedAccount('klienwp.com');

        $this->artisan('crm:wpscan')->assertExitCode(0);

        Http::assertNothingSent();
        // Insiden tetap dibuat walau WA mati.
        $this->assertSame(2, SecurityIncident::count());
    }

    // ---------- tidak ada situs sama sekali ----------

    public function test_empty_database_runs_cleanly(): void
    {
        Process::fake();

        $this->artisan('crm:wpscan')->assertExitCode(0);

        $this->assertSame(0, WpScanSite::count());
        $this->assertSame(0, SecurityIncident::count());
    }
}