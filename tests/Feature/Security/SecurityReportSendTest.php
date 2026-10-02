<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Enums\ReportStatus;
use App\Domains\Security\Jobs\SendSecurityReportEmailJob;
use App\Domains\Security\Mail\SecurityReportMailable;
use App\Domains\Security\Models\SecurityReport;
use App\Models\User;
use Database\Factories\ClientContactFactory;
use Database\Factories\ClientFactory;
use Database\Factories\SecurityReportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F4-3: tombol "Kirim ke Klien" pada laporan keamanan — mengirim PDF laporan
 * via email ke kontak klien + CC info@mcimedia.net, lalu menandai terkirim.
 */
class SecurityReportSendTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Laporan milik klien dengan email, lengkap dengan berkas PDF di disk. */
    private function reportWithFile(array $clientAttributes = [], array $reportAttributes = []): SecurityReport
    {
        Storage::fake('local');

        $client = ClientFactory::new()->create(array_merge([
            'name' => 'PT Rahasia Klien Abadi',
            'email' => 'kontak@rahasia-klien.test',
        ], $clientAttributes));

        $report = SecurityReportFactory::new()->create(array_merge([
            'client_id' => $client->id,
            'period' => '2026-09',
            'file_path' => 'security-reports/'.$client->id.'/laporan.pdf',
        ], $reportAttributes));

        Storage::disk('local')->put($report->file_path, "%PDF-1.4\n%%EOF\n");

        return $report->fresh();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $report = SecurityReportFactory::new()->create();

        $this->patch(route('security.reports.send-to-client', $report))
            ->assertRedirect(route('login'));
    }

    public function test_button_is_shown_on_reports_page_when_report_has_file(): void
    {
        $report = $this->reportWithFile();
        $this->login();

        $this->get(route('security.reports.index'))
            ->assertOk()
            ->assertSee('Kirim ke Klien')
            ->assertSee(route('security.reports.send-to-client', $report), false);
    }

    public function test_button_is_hidden_for_report_without_file(): void
    {
        $client = ClientFactory::new()->create();
        SecurityReportFactory::new()->create([
            'client_id' => $client->id,
            'period' => '2026-09',
            'file_path' => null,
        ]);
        $this->login();

        $this->get(route('security.reports.index'))
            ->assertOk()
            ->assertDontSee('Kirim ke Klien');
    }

    public function test_send_to_client_queues_the_email_job(): void
    {
        $report = $this->reportWithFile();
        $this->login();
        Queue::fake();

        $response = $this->patch(route('security.reports.send-to-client', $report));

        $response->assertSessionHas('success');
        Queue::assertPushed(SendSecurityReportEmailJob::class, fn ($job) => $job->report->is($report));
    }

    public function test_send_to_client_without_file_is_rejected(): void
    {
        $client = ClientFactory::new()->create(['email' => 'kontak@rahasia-klien.test']);
        $report = SecurityReportFactory::new()->create([
            'client_id' => $client->id,
            'period' => '2026-09',
            'file_path' => null,
        ]);
        $this->login();
        Queue::fake();

        $response = $this->patch(route('security.reports.send-to-client', $report));

        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
        $this->assertSame(ReportStatus::Draft, $report->fresh()->status);
    }

    public function test_send_to_client_without_recipient_is_rejected(): void
    {
        $client = ClientFactory::new()->create(['email' => null]);
        $report = SecurityReportFactory::new()->create([
            'client_id' => $client->id,
            'period' => '2026-09',
            'file_path' => 'security-reports/'.$client->id.'/laporan.pdf',
        ]);
        Storage::fake('local');
        Storage::disk('local')->put($report->file_path, '%PDF-1.4');
        $this->login();
        Queue::fake();

        $response = $this->patch(route('security.reports.send-to-client', $report));

        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
        $this->assertSame(ReportStatus::Draft, $report->fresh()->status);
    }

    public function test_send_to_client_rejects_when_file_missing_on_disk(): void
    {
        Storage::fake('local');
        $client = ClientFactory::new()->create(['email' => 'kontak@rahasia-klien.test']);
        $report = SecurityReportFactory::new()->create([
            'client_id' => $client->id,
            'file_path' => 'security-reports/'.$client->id.'/hilang.pdf',
        ]);
        $this->login();
        Queue::fake();

        $response = $this->patch(route('security.reports.send-to-client', $report));

        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
        $this->assertSame(ReportStatus::Draft, $report->fresh()->status);
    }

    public function test_job_emails_pdf_to_client_and_ccs_company(): void
    {
        $report = $this->reportWithFile();
        Mail::fake();

        (new SendSecurityReportEmailJob($report))->handle();

        Mail::assertSent(SecurityReportMailable::class, function (SecurityReportMailable $mail) {
            return $mail->hasTo('kontak@rahasia-klien.test')
                && $mail->hasCc('info@mcimedia.net');
        });
        Mail::assertSent(SecurityReportMailable::class, 1);
    }

    public function test_job_marks_report_as_sent_and_logs_activity(): void
    {
        $report = $this->reportWithFile();
        Mail::fake();

        (new SendSecurityReportEmailJob($report))->handle();

        $report->refresh();
        $this->assertSame(ReportStatus::Sent, $report->status);
        $this->assertNotNull($report->sent_at);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'email_sent',
            'subject_id' => $report->id,
        ]);
    }

    public function test_job_attaches_the_report_pdf(): void
    {
        $report = $this->reportWithFile();
        Mail::fake();

        (new SendSecurityReportEmailJob($report))->handle();

        Mail::assertSent(SecurityReportMailable::class, function (SecurityReportMailable $mail) use ($report) {
            $attachments = $mail->attachments();

            return count($attachments) === 1
                && $attachments[0]->as === $report->downloadName()
                && str_ends_with((string) $attachments[0]->as, '.pdf')
                && $attachments[0]->mime === 'application/pdf';
        });
    }

    public function test_job_includes_client_contact_emails(): void
    {
        $report = $this->reportWithFile();
        ClientContactFactory::new()->create([
            'client_id' => $report->client_id,
            'email' => 'teknisi@rahasia-klien.test',
        ]);
        Mail::fake();

        (new SendSecurityReportEmailJob($report->fresh('client.contacts')))->handle();

        Mail::assertSent(SecurityReportMailable::class, function (SecurityReportMailable $mail) {
            return $mail->hasTo('kontak@rahasia-klien.test')
                && $mail->hasTo('teknisi@rahasia-klien.test');
        });
    }

    public function test_job_cc_is_configurable_and_can_be_disabled(): void
    {
        config(['crm.security.report_cc_email' => null]);

        $report = $this->reportWithFile();
        Mail::fake();

        (new SendSecurityReportEmailJob($report))->handle();

        Mail::assertSent(SecurityReportMailable::class, fn (SecurityReportMailable $mail) => $mail->hasTo('kontak@rahasia-klien.test') && ! $mail->hasCc('info@mcimedia.net'));
    }

    public function test_job_skips_without_file_and_does_not_mark_sent(): void
    {
        Storage::fake('local');
        $client = ClientFactory::new()->create(['email' => 'kontak@rahasia-klien.test']);
        $report = SecurityReportFactory::new()->create([
            'client_id' => $client->id,
            'file_path' => null,
        ]);
        Mail::fake();

        (new SendSecurityReportEmailJob($report))->handle();

        Mail::assertNothingSent();
        $this->assertSame(ReportStatus::Draft, $report->fresh()->status);
    }

    public function test_email_template_omits_client_name_from_greeting(): void
    {
        $report = $this->reportWithFile();
        $report->loadMissing('client');

        $html = view('emails.security-report', [
            'report' => $report,
            'business' => config('crm.business'),
        ])->render();

        $this->assertStringContainsString('Dengan hormat', $html);
        $this->assertStringContainsString('2026-09', $html);
        $this->assertStringNotContainsString('PT Rahasia Klien Abadi', $html);
    }

    public function test_resend_keeps_original_sent_at(): void
    {
        $report = $this->reportWithFile(reportAttributes: ['status' => ReportStatus::Sent, 'sent_at' => now()->subDay()]);
        $originalSentAt = $report->sent_at;
        Mail::fake();

        (new SendSecurityReportEmailJob($report))->handle();

        Mail::assertSent(SecurityReportMailable::class, 1);
        $this->assertTrue($report->fresh()->sent_at->equalTo($originalSentAt));
    }
}
