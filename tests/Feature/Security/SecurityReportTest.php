<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Enums\ReportStatus;
use App\Domains\Security\Models\SecurityReport;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\SecurityReportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityReportTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Berkas PDF minimal yang benar-benar dikenali sebagai application/pdf. */
    private function pdf(string $name = 'laporan.pdf', int $paddingBytes = 0): UploadedFile
    {
        $content = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

        if ($paddingBytes > 0) {
            $content .= str_repeat('A', $paddingBytes);
        }

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('security.reports.index'))->assertRedirect(route('login'));
    }

    public function test_can_upload_pdf_report(): void
    {
        Storage::fake('local');
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('security.reports.store'), [
            'client_id' => $client->id,
            'period' => '2026-09',
            'file' => $this->pdf('laporan-september.pdf'),
        ]);

        $response->assertRedirect(route('security.reports.index'));
        $response->assertSessionHas('success');

        $report = SecurityReport::firstOrFail();
        $this->assertSame('2026-09', $report->period);
        $this->assertSame($client->id, $report->client_id);
        $this->assertSame(ReportStatus::Draft, $report->status);
        $this->assertNotNull($report->file_path);
        Storage::disk('local')->assertExists($report->file_path);
    }

    public function test_upload_rejects_non_pdf_file(): void
    {
        Storage::fake('local');
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('security.reports.store'), [
            'client_id' => $client->id,
            'period' => '2026-09',
            'file' => UploadedFile::fake()->createWithContent('catatan.txt', 'bukan pdf sama sekali'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('security_reports', 0);
    }

    public function test_upload_rejects_file_over_size_limit(): void
    {
        Storage::fake('local');
        config(['crm.security.report_max_kb' => 1]);
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('security.reports.store'), [
            'client_id' => $client->id,
            'period' => '2026-09',
            'file' => $this->pdf('besar.pdf', 4000), // > 1 KB
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('security_reports', 0);
    }

    public function test_upload_validates_period_format_and_required_fields(): void
    {
        $this->login();

        $this->post(route('security.reports.store'), [
            'client_id' => '',
            'period' => 'September 2026',
            'file' => null,
        ])->assertSessionHasErrors(['client_id', 'period', 'file']);

        $this->assertDatabaseCount('security_reports', 0);
    }

    public function test_admin_can_download_report(): void
    {
        Storage::fake('local');
        $this->login();
        $report = SecurityReportFactory::new()->create();
        Storage::disk('local')->put($report->file_path = 'security-reports/1/laporan.pdf', '%PDF-1.4');
        $report->save();

        $response = $this->get(route('security.reports.download', $report));

        $response->assertOk();
        $this->assertStringContainsString('Laporan-Keamanan-', $response->headers->get('content-disposition'));
    }

    public function test_download_missing_file_returns_404(): void
    {
        Storage::fake('local');
        $this->login();
        $report = SecurityReportFactory::new()->create(['file_path' => 'security-reports/1/hilang.pdf']);

        $this->get(route('security.reports.download', $report))->assertNotFound();
    }

    public function test_send_marks_report_terkirim(): void
    {
        $this->login();
        $report = SecurityReportFactory::new()->create();

        $this->patch(route('security.reports.send', $report))->assertSessionHas('success');

        $report->refresh();
        $this->assertSame(ReportStatus::Sent, $report->status);
        $this->assertNotNull($report->sent_at);
    }

    public function test_destroy_removes_report_and_its_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('security-reports/1/laporan.pdf', '%PDF-1.4');
        $this->login();
        $report = SecurityReportFactory::new()->create(['file_path' => 'security-reports/1/laporan.pdf']);

        $this->delete(route('security.reports.destroy', $report))->assertSessionHas('success');

        $this->assertDatabaseMissing('security_reports', ['id' => $report->id]);
        Storage::disk('local')->assertMissing('security-reports/1/laporan.pdf');
    }

    public function test_index_filters_by_client(): void
    {
        $this->login();
        $a = ClientFactory::new()->create();
        $b = ClientFactory::new()->create();

        SecurityReportFactory::new()->create(['client_id' => $a->id, 'period' => '2026-08']);
        SecurityReportFactory::new()->create(['client_id' => $b->id, 'period' => '2026-07']);

        $this->get(route('security.reports.index', ['client_id' => $a->id]))
            ->assertOk()
            ->assertSee('2026-08')
            ->assertDontSee('2026-07');
    }
}
