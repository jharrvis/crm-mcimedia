<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Enums\ReportStatus;
use App\Domains\Security\Jobs\SendPortalSecurityReportEmailJob;
use App\Domains\Security\Mail\SecurityReportMailable;
use App\Domains\Security\Models\SecurityReport;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\SecurityReportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F3-3/F4-4: portal laporan keamanan publik (magic link) — daftar laporan
 * terkirim + tombol "Kirim via Email" yang mengirim PDF ke email terdaftar
 * klien (bukan unduh langsung).
 */
class SecurityPortalTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Klien dengan token portal aktif. */
    private function clientWithPortal(array $attributes = []): \App\Domains\Clients\Models\Client
    {
        $client = ClientFactory::new()->create($attributes);
        $client->forceFill(['security_portal_token' => Str::random(64)])->save();

        return $client;
    }

    /** Laporan terkirim milik klien, dengan/ tanpa berkas PDF di disk. */
    private function sentReport($client, array $attributes = []): SecurityReport
    {
        return SecurityReportFactory::new()->sent()->create(array_merge([
            'client_id' => $client->id,
            'period' => '2026-08',
            'file_path' => 'security-reports/'.$client->id.'/laporan.pdf',
        ], $attributes));
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

    public function test_portal_shows_email_button_and_never_shows_direct_download(): void
    {
        Storage::fake('local');
        $client = $this->clientWithPortal();
        $report = $this->sentReport($client, ['period' => '2026-08']);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        $response = $this->get(route('security.portal.show', ['token' => $client->security_portal_token]));

        $response->assertOk();
        $response->assertSee('Kirim via Email');
        $response->assertSee(route('security.portal.email', [
            'token' => $client->security_portal_token,
            'report' => $report,
        ]), false);
        // Unduh langsung sudah dihapus dari portal (F4-4).
        $response->assertDontSee('Unduh PDF');
        $response->assertDontSee('security/report/'.$client->security_portal_token.'/reports/'.$report->id.'/download');
    }

    public function test_reports_without_file_show_no_email_button(): void
    {
        $client = $this->clientWithPortal();
        $this->sentReport($client, ['period' => '2026-08', 'file_path' => null]);

        $this->get(route('security.portal.show', ['token' => $client->security_portal_token]))
            ->assertOk()
            ->assertDontSee('Kirim via Email');
    }

    public function test_public_download_route_no_longer_exists(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('security.portal.download'));

        $client = $this->clientWithPortal();
        $report = $this->sentReport($client);

        $this->get("/security/report/{$client->security_portal_token}/reports/{$report->id}/download")
            ->assertNotFound();
    }

    public function test_client_can_request_report_by_email(): void
    {
        Storage::fake('local');
        Queue::fake();
        $client = $this->clientWithPortal(['email' => 'klien@contoh.test']);
        $report = $this->sentReport($client, ['period' => '2026-08']);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        $this->post(route('security.portal.email', [
            'token' => $client->security_portal_token,
            'report' => $report,
        ]))
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        Queue::assertPushed(SendPortalSecurityReportEmailJob::class, function ($job) use ($report) {
            return $job->report->is($report);
        });
    }

    public function test_email_request_with_unknown_token_returns_404(): void
    {
        Queue::fake();
        $report = SecurityReportFactory::new()->sent()->create(['file_path' => 'x.pdf']);

        $this->post(route('security.portal.email', [
            'token' => Str::random(64),
            'report' => $report,
        ]))->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_client_cannot_request_another_clients_report_by_email(): void
    {
        Storage::fake('local');
        Queue::fake();
        $mine = $this->clientWithPortal();
        $other = ClientFactory::new()->create();

        $report = SecurityReportFactory::new()->sent()->create([
            'client_id' => $other->id,
            'file_path' => 'security-reports/'.$other->id.'/laporan.pdf',
        ]);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        $this->post(route('security.portal.email', [
            'token' => $mine->security_portal_token,
            'report' => $report,
        ]))->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_client_cannot_request_draft_report_by_email(): void
    {
        Storage::fake('local');
        Queue::fake();
        $client = $this->clientWithPortal();
        $report = SecurityReportFactory::new()->create([
            'client_id' => $client->id,
            'period' => '2026-08',
            'file_path' => 'security-reports/'.$client->id.'/draf.pdf',
            'status' => ReportStatus::Draft,
        ]);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        $this->post(route('security.portal.email', [
            'token' => $client->security_portal_token,
            'report' => $report,
        ]))->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_request_is_rejected_when_pdf_file_is_missing(): void
    {
        Storage::fake('local');
        Queue::fake();
        $client = $this->clientWithPortal();
        // hasFile() true (ada path) tetapi berkas tidak diletakkan di disk.
        $report = $this->sentReport($client);

        $this->post(route('security.portal.email', [
            'token' => $client->security_portal_token,
            'report' => $report,
        ]))->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_request_is_rejected_when_client_has_no_valid_email(): void
    {
        Storage::fake('local');
        Queue::fake();
        $client = $this->clientWithPortal(['email' => '']);
        $report = $this->sentReport($client);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        $this->post(route('security.portal.email', [
            'token' => $client->security_portal_token,
            'report' => $report,
        ]))->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_cooldown_prevents_immediate_resend(): void
    {
        Storage::fake('local');
        Queue::fake();
        $client = $this->clientWithPortal();
        $report = $this->sentReport($client);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        $url = route('security.portal.email', [
            'token' => $client->security_portal_token,
            'report' => $report,
        ]);

        $this->post($url)->assertSessionHas('success');
        $this->post($url)->assertSessionHas('error');

        Queue::assertPushed(SendPortalSecurityReportEmailJob::class, 1);
    }

    public function test_job_sends_pdf_to_registered_client_email_only(): void
    {
        Storage::fake('local');
        Mail::fake();
        $client = $this->clientWithPortal(['name' => 'PT Klien Rahasia', 'email' => 'klien@contoh.test']);
        $report = $this->sentReport($client, ['period' => '2026-08']);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        (new SendPortalSecurityReportEmailJob($report))->handle();

        Mail::assertSent(SecurityReportMailable::class, function (SecurityReportMailable $mail) {
            return $mail->hasTo('klien@contoh.test')
                && count($mail->attachments()) === 1;
        });
        Mail::assertSent(SecurityReportMailable::class, 1);
    }

    public function test_job_is_skipped_when_client_email_invalid(): void
    {
        Storage::fake('local');
        Mail::fake();
        $client = $this->clientWithPortal(['email' => 'bukan-email']);
        $report = $this->sentReport($client);
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');

        (new SendPortalSecurityReportEmailJob($report))->handle();

        Mail::assertNothingSent();
    }
}
