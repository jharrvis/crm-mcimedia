<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F2-9: multi-rekening bank pada PDF invoice, halaman bayar publik, dan email.
 *
 * Fokus: daftar rekening env-driven (bukan hardcode), render dengan 0/1/2
 * rekening tanpa error, dan fallback rapi saat daftar kosong.
 */
class BankAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const BCA = ['name' => 'BCA', 'account_number' => '1111222233', 'account_holder' => 'Nama Pemilik'];

    private const JATENG = ['name' => 'Bank Jateng', 'account_number' => '4444555566', 'account_holder' => 'Nama Pemilik'];

    /** Invoice terkirim dengan tautan publik aktif (siap dirender). */
    private function invoice(): Invoice
    {
        return InvoiceFactory::new()->withItems(800000)->create([
            'status' => InvoiceStatus::Sent,
            'public_token' => Str::random(64),
        ]);
    }

    /** Jalankan callback dengan env var sementara; selalu dipulihkan. */
    private function withEnv(string $key, string $value, callable $callback): mixed
    {
        $oldEnv = $_ENV[$key] ?? null;
        $oldServer = $_SERVER[$key] ?? null;
        $oldPutenv = getenv($key);

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");

        try {
            return $callback();
        } finally {
            if ($oldEnv === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $oldEnv;
            }

            if ($oldServer === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $oldServer;
            }

            $oldPutenv === false ? putenv($key) : putenv("{$key}={$oldPutenv}");
        }
    }

    // ---------- normalisasi helper ----------

    public function test_normalize_trims_and_drops_empty_entries(): void
    {
        $result = crm_normalize_bank_accounts([
            ['name' => '  BCA ', 'account_number' => ' 1111222233 ', 'account_holder' => ' Nama Pemilik '],
            ['name' => '', 'account_number' => '', 'account_holder' => ''],
            ['name' => 'Mandiri', 'account_number' => '', 'account_holder' => ''],
            'bukan-array',
        ]);

        $this->assertSame([
            ['name' => 'BCA', 'account_number' => '1111222233', 'account_holder' => 'Nama Pemilik'],
            ['name' => 'Mandiri', 'account_number' => '', 'account_holder' => ''],
        ], $result);
    }

    public function test_normalize_handles_non_array_input(): void
    {
        $this->assertSame([], crm_normalize_bank_accounts(null));
        $this->assertSame([], crm_normalize_bank_accounts('bukan array'));
        $this->assertSame([], crm_normalize_bank_accounts(42));
    }

    public function test_crm_bank_accounts_reads_config_and_normalizes(): void
    {
        config(['crm.bank.accounts' => [
            self::BCA,
            ['name' => '', 'account_number' => '', 'account_holder' => ''],
        ]]);

        $accounts = crm_bank_accounts();

        $this->assertCount(1, $accounts);
        $this->assertSame('1111222233', $accounts[0]['account_number']);
    }

    // ---------- env parsing ----------

    public function test_env_json_with_two_accounts_is_parsed(): void
    {
        $json = json_encode([self::BCA, self::JATENG]);

        $accounts = $this->withEnv('CRM_BANK_ACCOUNTS', $json, fn () => crm_env_bank_accounts());

        $this->assertCount(2, $accounts);
        $this->assertSame('BCA', $accounts[0]['name']);
        $this->assertSame('1111222233', $accounts[0]['account_number']);
        $this->assertSame('Bank Jateng', $accounts[1]['name']);
        $this->assertSame('4444555566', $accounts[1]['account_number']);
        $this->assertSame('Nama Pemilik', $accounts[1]['account_holder']);
    }

    public function test_env_json_with_one_account_is_parsed(): void
    {
        $accounts = $this->withEnv(
            'CRM_BANK_ACCOUNTS',
            json_encode([self::BCA]),
            fn () => crm_env_bank_accounts()
        );

        $this->assertCount(1, $accounts);
        $this->assertSame('1111222233', $accounts[0]['account_number']);
    }

    public function test_invalid_json_falls_back_to_legacy_single_variables(): void
    {
        $accounts = $this->withEnv('CRM_BANK_ACCOUNTS', '{bukan json', function () {
            return $this->withEnv('CRM_BANK_NAME', 'BCA', function () {
                return $this->withEnv('CRM_BANK_ACCOUNT_NUMBER', '1111222233', function () {
                    return $this->withEnv('CRM_BANK_ACCOUNT_HOLDER', 'Nama Pemilik', fn () => crm_env_bank_accounts());
                });
            });
        });

        $this->assertCount(1, $accounts);
        $this->assertSame('BCA', $accounts[0]['name']);
        $this->assertSame('1111222233', $accounts[0]['account_number']);
    }

    public function test_legacy_single_variables_still_supported(): void
    {
        $accounts = $this->withEnv('CRM_BANK_ACCOUNTS', '', function () {
            return $this->withEnv('CRM_BANK_NAME', 'Bank Jateng', function () {
                return $this->withEnv('CRM_BANK_ACCOUNT_NUMBER', '4444555566', function () {
                    return $this->withEnv('CRM_BANK_ACCOUNT_HOLDER', 'Nama Pemilik', fn () => crm_env_bank_accounts());
                });
            });
        });

        $this->assertCount(1, $accounts);
        $this->assertSame('4444555566', $accounts[0]['account_number']);
    }

    public function test_without_any_env_the_list_is_empty(): void
    {
        $accounts = $this->withEnv('CRM_BANK_ACCOUNTS', '', function () {
            return $this->withEnv('CRM_BANK_NAME', '', function () {
                return $this->withEnv('CRM_BANK_ACCOUNT_NUMBER', '', fn () => crm_env_bank_accounts());
            });
        });

        $this->assertSame([], $accounts);
    }

    // ---------- render: 2 rekening ----------

    public function test_pdf_shows_all_configured_accounts(): void
    {
        config(['crm.bank.accounts' => [self::BCA, self::JATENG]]);

        $html = view('invoices.pdf', [
            'invoice' => $this->invoice()->load(['client', 'items', 'payments']),
        ])->render();

        $this->assertStringContainsString('1111222233', $html);
        $this->assertStringContainsString('4444555566', $html);
        $this->assertStringContainsString('BCA', $html);
        $this->assertStringContainsString('Bank Jateng', $html);
        $this->assertStringContainsString('Nama Pemilik', $html);
        $this->assertStringContainsString('salah satu', $html);
    }

    public function test_public_page_shows_all_configured_accounts(): void
    {
        config(['crm.bank.accounts' => [self::BCA, self::JATENG]]);
        $invoice = $this->invoice();

        $response = $this->get(route('invoices.public.show', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertSee('1111222233');
        $response->assertSee('4444555566');
        $response->assertSee('BCA');
        $response->assertSee('Bank Jateng');
        $response->assertSee('Nama Pemilik');
    }

    public function test_public_pdf_download_renders_all_accounts(): void
    {
        config(['crm.bank.accounts' => [self::BCA, self::JATENG]]);
        $invoice = $this->invoice();

        $response = $this->get(route('invoices.public.pdf', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->baseResponse->getContent());
    }

    public function test_invoice_and_reminder_emails_show_all_accounts(): void
    {
        config(['crm.bank.accounts' => [self::BCA, self::JATENG]]);
        $invoice = $this->invoice()->load('client');

        $invoiceEmail = view('emails.invoice', [
            'invoice' => $invoice,
            'business' => config('crm.business'),
            'paymentUrl' => null,
            'pdfUrl' => null,
        ])->render();

        $this->assertStringContainsString('1111222233', $invoiceEmail);
        $this->assertStringContainsString('4444555566', $invoiceEmail);

        $reminderEmail = view('emails.invoice-reminder', [
            'invoice' => $invoice,
            'business' => config('crm.business'),
            'paymentUrl' => null,
            'kind' => \App\Domains\Invoicing\Enums\InvoiceReminderKind::H1,
            'daysOverdue' => 1,
        ])->render();

        $this->assertStringContainsString('1111222233', $reminderEmail);
        $this->assertStringContainsString('4444555566', $reminderEmail);
    }

    // ---------- render: 1 rekening ----------

    public function test_single_account_renders_without_the_other(): void
    {
        config(['crm.bank.accounts' => [self::BCA]]);
        $invoice = $this->invoice();

        $response = $this->get(route('invoices.public.show', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertSee('1111222233');
        $response->assertDontSee('4444555566');
    }

    public function test_single_account_pdf_renders(): void
    {
        config(['crm.bank.accounts' => [self::JATENG]]);

        $html = view('invoices.pdf', [
            'invoice' => $this->invoice()->load(['client', 'items', 'payments']),
        ])->render();

        $this->assertStringContainsString('4444555566', $html);
        $this->assertStringNotContainsString('1111222233', $html);
    }

    // ---------- render: 0 rekening (fallback rapi) ----------

    public function test_empty_accounts_render_graceful_fallback_on_all_surfaces(): void
    {
        config(['crm.bank.accounts' => []]);
        $invoice = $this->invoice();

        // Halaman publik: tetap 200 + pesan fallback, bukan error.
        $response = $this->get(route('invoices.public.show', ['token' => $invoice->public_token]));
        $response->assertOk();
        $response->assertSee('Detail rekening tujuan belum tersedia');

        // PDF: tetap ter-render dan menampilkan fallback.
        $html = view('invoices.pdf', [
            'invoice' => $invoice->load(['client', 'items', 'payments']),
        ])->render();
        $this->assertStringContainsString('Detail rekening tujuan belum tersedia', $html);

        // Email: fallback juga, tanpa error.
        $email = view('emails.invoice', [
            'invoice' => $invoice->load('client'),
            'business' => config('crm.business'),
            'paymentUrl' => null,
            'pdfUrl' => null,
        ])->render();
        $this->assertStringContainsString('Detail rekening tujuan belum tersedia', $email);

        // PDF publik tetap bisa diunduh.
        $this->get(route('invoices.public.pdf', ['token' => $invoice->public_token]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_account_with_missing_parts_still_renders_without_blank_crash(): void
    {
        config(['crm.bank.accounts' => [
            ['name' => 'BCA', 'account_number' => '1111222233', 'account_holder' => ''],
        ]]);

        $html = view('invoices.pdf', [
            'invoice' => $this->invoice()->load(['client', 'items', 'payments']),
        ])->render();

        $this->assertStringContainsString('1111222233', $html);
    }
}
