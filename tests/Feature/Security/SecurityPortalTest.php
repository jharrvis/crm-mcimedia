<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Enums\ReportStatus;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\SecurityReportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityPortalTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_admin_generates_and_revokes_portal_token(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $this->assertFalse($client->hasSecurityPortal());

        $this->post(route('clients.security-portal.generate', $client))->assertSessionHas('success');

        $client->refresh();
        $this->assertNotNull($client->security_portal_token);
        $this->assertSame(64, strlen($client->security_portal_token));
        $this->assertTrue($client->hasSecurityPortal());

        $this->delete(route('clients.security-portal.revoke', $client))->assertSessionHas('success');

        $this->assertNull($client->fresh()->security_portal_token);
    }

    public function test_valid_token_lists_only_sent_reports(): void
    {
        $client = ClientFactory::new()->create(['name' => 'YIARI']);
        $client->forceFill(['security_portal_token' => Str::random(64)])->save();

        SecurityReportFactory::new()->sent()->create(['client_id' => $client->id, 'period' => '2026-08']);
        SecurityReportFactory::new()->create(['client_id' => $client->id, 'period' => '2026-09']); // draft

        $response = $this->get(route('security.portal.show', ['token' => $client->security_portal_token]));

        $response->assertOk();
        $response->assertSee('YIARI');
        $response->assertSee('2026-08');
        $response->assertDontSee('2026-09'); // draf tidak dipublikasikan
    }

    public function test_portal_routes_are_not_behind_auth(): void
    {
        $client = ClientFactory::new()->create();
        $client->forceFill(['security_portal_token' => Str::random(64)])->save();

        $this->get(route('security.portal.show', ['token' => $client->security_portal_token]))->assertOk();
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->get(route('security.portal.show', ['token' => Str::random(64)]))->assertNotFound();
    }

    public function test_revoked_token_returns_404(): void
    {
        $client = ClientFactory::new()->create();
        $token = $client->security_portal_token = Str::random(64);
        $client->save();

        $client->forceFill(['security_portal_token' => null])->save();

        $this->get(route('security.portal.show', ['token' => $token]))->assertNotFound();
    }

    public function test_public_page_does_not_leak_other_client_data(): void
    {
        $mine = ClientFactory::new()->create(['name' => 'Klien Pemilik', 'email' => 'rahasia@example.com']);
        $other = ClientFactory::new()->create(['name' => 'Klien Lain Corp']);
        $mine->forceFill(['security_portal_token' => Str::random(64)])->save();

        SecurityReportFactory::new()->sent()->create(['client_id' => $mine->id, 'period' => '2026-08']);
        SecurityReportFactory::new()->sent()->create(['client_id' => $other->id, 'period' => '2026-07']);

        $response = $this->get(route('security.portal.show', ['token' => $mine->security_portal_token]));

        $response->assertOk();
        $response->assertSee('Klien Pemilik');
        $response->assertDontSee('Klien Lain Corp');
        $response->assertDontSee('2026-07');
        $response->assertDontSee('rahasia@example.com');
    }

    public function test_reports_without_file_show_no_download_link(): void
    {
        $client = ClientFactory::new()->create();
        $client->forceFill(['security_portal_token' => Str::random(64)])->save();
        SecurityReportFactory::new()->sent()->create(['client_id' => $client->id, 'period' => '2026-08', 'file_path' => null]);

        $this->get(route('security.portal.show', ['token' => $client->security_portal_token]))
            ->assertOk()
            ->assertDontSee('Unduh PDF');
    }

    public function test_client_can_download_sent_report(): void
    {
        Storage::fake('local');
        $client = ClientFactory::new()->create();
        $client->forceFill(['security_portal_token' => Str::random(64)])->save();

        $report = SecurityReportFactory::new()->sent()->create([
            'client_id' => $client->id,
            'period' => '2026-08',
            'file_path' => 'security-reports/1/laporan.pdf',
        ]);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        $response = $this->get(route('security.portal.download', [
            'token' => $client->security_portal_token,
            'report' => $report,
        ]));

        $response->assertOk();
        $this->assertStringContainsString('Laporan-Keamanan-', $response->headers->get('content-disposition'));
    }

    public function test_client_cannot_download_draft_report(): void
    {
        Storage::fake('local');
        $client = ClientFactory::new()->create();
        $client->forceFill(['security_portal_token' => Str::random(64)])->save();

        $report = SecurityReportFactory::new()->create([
            'client_id' => $client->id,
            'file_path' => 'security-reports/1/draf.pdf',
            'status' => ReportStatus::Draft,
        ]);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        $this->get(route('security.portal.download', [
            'token' => $client->security_portal_token,
            'report' => $report,
        ]))->assertNotFound();
    }

    public function test_client_cannot_download_another_clients_report(): void
    {
        Storage::fake('local');
        $mine = ClientFactory::new()->create();
        $mine->forceFill(['security_portal_token' => Str::random(64)])->save();
        $other = ClientFactory::new()->create();

        $report = SecurityReportFactory::new()->sent()->create([
            'client_id' => $other->id,
            'file_path' => 'security-reports/2/laporan.pdf',
        ]);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        $this->get(route('security.portal.download', [
            'token' => $mine->security_portal_token,
            'report' => $report,
        ]))->assertNotFound();
    }

    public function test_download_with_wrong_token_returns_404(): void
    {
        $report = SecurityReportFactory::new()->sent()->create(['file_path' => 'x.pdf']);

        $this->get(route('security.portal.download', [
            'token' => Str::random(64),
            'report' => $report,
        ]))->assertNotFound();
    }
}
