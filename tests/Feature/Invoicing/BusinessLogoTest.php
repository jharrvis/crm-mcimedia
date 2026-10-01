<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Models\User;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F2-8 — logo usaha pada kop PDF invoice & header halaman bayar publik.
 *
 * File logo disimpan di public/ (path relatif dari env CRM_BUSINESS_LOGO).
 * Test memakai file dummy di public/images/ dengan nama unik dan menghapusnya
 * kembali agar tidak mengganggu aset produksi.
 */
class BusinessLogoTest extends TestCase
{
    use RefreshDatabase;

    /** Path relatif (terhadap public/) untuk file dummy test. */
    private const LOGO_RELATIVE = 'images/test-logo-f2-8.png';

    /** PNG 1x1 transparan yang valid. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $logoAbsolute;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logoAbsolute = public_path(self::LOGO_RELATIVE);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logoAbsolute)) {
            @unlink($this->logoAbsolute);
        }

        parent::tearDown();
    }

    /** Taruh file logo dummy di public/ dan arahkan config ke sana. */
    private function installLogo(): void
    {
        if (! is_dir(dirname($this->logoAbsolute))) {
            mkdir(dirname($this->logoAbsolute), 0755, true);
        }

        file_put_contents($this->logoAbsolute, base64_decode(self::PNG_BASE64));

        config(['crm.business.logo' => self::LOGO_RELATIVE]);
    }

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function sentInvoice(int $total = 750000): Invoice
    {
        return InvoiceFactory::new()->withItems($total)->create([
            'status' => InvoiceStatus::Sent,
            'public_token' => Str::random(64),
        ]);
    }

    // ---------- helper ----------

    public function test_helper_returns_null_when_logo_unset(): void
    {
        config(['crm.business.logo' => null]);

        $this->assertNull(business_logo_path());
        $this->assertNull(business_logo_url());
    }

    public function test_helper_returns_null_when_logo_file_missing(): void
    {
        config(['crm.business.logo' => 'images/does-not-exist-f2-8.png']);

        $this->assertNull(business_logo_path());
        $this->assertNull(business_logo_url());
    }

    public function test_helper_returns_local_path_and_url_when_logo_present(): void
    {
        $this->installLogo();

        $this->assertSame($this->logoAbsolute, business_logo_path());
        $this->assertSame(asset(self::LOGO_RELATIVE), business_logo_url());
    }

    // ---------- PDF invoice ----------

    public function test_pdf_view_renders_logo_when_configured(): void
    {
        $this->installLogo();
        $invoice = InvoiceFactory::new()->withItems(1500000)->create();

        $html = view('invoices.pdf', ['invoice' => $invoice->load(['client', 'items', 'payments'])])->render();

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString($this->logoAbsolute, $html);
        $this->assertStringContainsString('biz-logo', $html);
        $this->assertStringContainsString(config('crm.business.name'), $html);
    }

    public function test_pdf_download_works_with_logo_configured(): void
    {
        $this->installLogo();
        $this->login();
        $invoice = InvoiceFactory::new()->withItems(1500000)->create();

        $response = $this->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertDownload($invoice->number.'.pdf');
        $this->assertStringStartsWith('%PDF', $response->baseResponse->getContent());
    }

    public function test_pdf_view_has_no_logo_when_not_configured(): void
    {
        config(['crm.business.logo' => null]);
        $invoice = InvoiceFactory::new()->withItems(1500000)->create();

        $html = view('invoices.pdf', ['invoice' => $invoice->load(['client', 'items', 'payments'])])->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('biz-logo', $html);
        $this->assertStringContainsString(config('crm.business.name'), $html);
    }

    public function test_pdf_view_skips_logo_when_file_missing(): void
    {
        config(['crm.business.logo' => 'images/does-not-exist-f2-8.png']);
        $invoice = InvoiceFactory::new()->withItems(1500000)->create();

        $html = view('invoices.pdf', ['invoice' => $invoice->load(['client', 'items', 'payments'])])->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString(config('crm.business.name'), $html);
    }

    public function test_pdf_download_works_without_logo(): void
    {
        config(['crm.business.logo' => null]);
        $this->login();
        $invoice = InvoiceFactory::new()->withItems(1500000)->create();

        $response = $this->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->baseResponse->getContent());
    }

    // ---------- halaman bayar publik ----------

    public function test_public_page_shows_logo_when_configured(): void
    {
        $this->installLogo();
        $invoice = $this->sentInvoice();

        $response = $this->get(route('invoices.public.show', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertSee(asset(self::LOGO_RELATIVE), false);
        $response->assertSee($invoice->number);
    }

    public function test_public_page_falls_back_to_initial_when_logo_unset(): void
    {
        config(['crm.business.logo' => null]);
        $invoice = $this->sentInvoice();

        $response = $this->get(route('invoices.public.show', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertDontSee(asset(self::LOGO_RELATIVE), false);
        // Kotak inisial "M" tetap tampil sebagai fallback.
        $response->assertSee('bg-indigo-600', false);
    }

    public function test_public_pdf_download_works_with_logo_configured(): void
    {
        $this->installLogo();
        $invoice = $this->sentInvoice();

        $response = $this->get(route('invoices.public.pdf', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->baseResponse->getContent());
    }
}
