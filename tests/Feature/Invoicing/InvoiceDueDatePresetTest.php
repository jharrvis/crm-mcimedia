<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Models\Invoice;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * UX-3: pilihan cepat jatuh tempo di form invoice.
 *
 * Test ini menutup dua sisi yang tidak bisa disentuh test JS:
 *  1. Markup panel preset + kalender benar-benar ada di form create & edit.
 *  2. Nilai preset (yang dikirim hidden input berformat Y-m-d) tersimpan
 *     benar ke kolom invoices.due_date.
 *
 * Matematika preset itu sendiri diuji di tests/JS/due-date.test.mjs.
 */
class InvoiceDueDatePresetTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** 7 opsi sesuai referensi: Kustom + 6 preset tanggal. */
    public static function presetOptions(): array
    {
        return [
            'kustom' => ['custom', 'Kustom'],
            'hari ini' => ['today', 'Hari ini'],
            '7 hari' => ['7', '7 Hari Selanjutnya'],
            '14 hari' => ['14', '14 Hari Selanjutnya'],
            '30 hari' => ['30', '30 Hari Selanjutnya'],
            '45 hari' => ['45', '45 Hari Selanjutnya'],
            '60 hari' => ['60', '60 Hari Selanjutnya'],
        ];
    }

    private function payload($client, string $dueDate): array
    {
        return [
            'client_id' => $client->id,
            'service_ids' => [],
            'title' => 'Perpanjangan Hosting',
            'issue_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'items' => [
                ['description' => 'Hosting Bisnis', 'quantity' => 1, 'unit_price' => 150000],
            ],
        ];
    }

    // ---------- panel preset ada di form ----------

    public function test_create_form_renders_all_seven_preset_options(): void
    {
        $this->login();

        $response = $this->get(route('invoices.create'));

        $response->assertOk();

        foreach (self::presetOptions() as [$key, $label]) {
            $response->assertSee('data-due-preset="'.$key.'"', false);
            $response->assertSee($label);
        }
    }

    public function test_edit_form_renders_all_seven_preset_options(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->withItems()->create();

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk();

        foreach (self::presetOptions() as [$key, $label]) {
            $response->assertSee('data-due-preset="'.$key.'"', false);
        }
    }

    public function test_panel_has_calendar_with_month_navigation_and_indonesian_names(): void
    {
        $this->login();

        $response = $this->get(route('invoices.create'));

        $response->assertOk()
            ->assertSee('data-due-calendar', false)              // kalender bulan di panel kanan
            ->assertSee('data-due-prev-month', false)            // navigasi < bulan
            ->assertSee('data-due-next-month', false)            // navigasi > bulan
            ->assertSee('data-due-days', false)
            ->assertSee('data-due-weekdays', false)
            ->assertSee('data-due-day', false)                    // tombol tanggal (dirender JS)
            ->assertSee('Oktober', false)                         // nama bulan bahasa Indonesia
            ->assertSee('"Sn","Sl","Rb","Km","Jm","Sb","Mg"', false); // Sn–Mg
    }

    public function test_form_keeps_hidden_input_named_due_date_in_iso_format(): void
    {
        $this->login();
        $expected = now()->addDays(14)->toDateString();

        $response = $this->get(route('invoices.create'));

        $response->assertOk()
            ->assertSee('name="due_date"', false)
            ->assertSee('value="'.$expected.'"', false)
            ->assertSee('data-due-input', false);
    }

    public function test_create_form_shows_display_value_in_dd_mm_yyyy(): void
    {
        $this->login();
        // Default form = hari ini + 14 hari (preset bawaan, sama seperti sebelumnya).
        $default = now()->addDays(14)->toDateString();

        $response = $this->get(route('invoices.create'));

        $response->assertOk()
            ->assertSee('data-due-display', false)
            ->assertSee(tgl_id($default));
    }

    public function test_edit_form_shows_existing_due_date_not_a_preset_default(): void
    {
        $this->login();
        // Tanggal bebas (bukan hasil preset) — harus tampil apa adanya.
        $invoice = InvoiceFactory::new()->withItems()->create([
            'issue_date' => '2026-01-01',
            'due_date' => '2026-03-17',
        ]);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('value="2026-03-17"', false)
            ->assertSee(tgl_id('2026-03-17'))
            ->assertSee('data-selected-preset="custom"', false);
    }

    // ---------- preset aktif ditandai sesuai nilai ----------

    public function test_active_preset_is_marked_for_each_preset_value(): void
    {
        $this->login();

        $expected = [
            0 => 'today',
            7 => '7',
            14 => '14',
            30 => '30',
            45 => '45',
            60 => '60',
        ];

        foreach ($expected as $days => $preset) {
            $invoice = InvoiceFactory::new()->withItems()->create([
                'issue_date' => now()->subDays(5)->toDateString(),
                'due_date' => now()->addDays($days)->toDateString(),
            ]);

            $this->get(route('invoices.edit', $invoice))
                ->assertOk()
                ->assertSee('data-selected-preset="'.$preset.'"', false);
        }
    }

    // ---------- nilai tersimpan ke DB ----------

    public static function storedDueDateProvider(): array
    {
        return [
            'hari ini' => [0, 'today'],
            'plus 7' => [7, '7'],
            'plus 14' => [14, '14'],
            'plus 30' => [30, '30'],
            'plus 45' => [45, '45'],
            'plus 60' => [60, '60'],
        ];
    }

    #[DataProvider('storedDueDateProvider')]
    public function test_preset_date_is_stored_in_due_date_column(int $days): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $expected = now()->addDays($days)->toDateString();

        $this->post(route('invoices.store'), $this->payload($client, $expected))
            ->assertSessionHas('success');

        $invoice = Invoice::firstOrFail();

        $this->assertSame($expected, $invoice->due_date->toDateString());
        $this->assertDueDateStored($invoice->id, $expected);
    }

    /**
 * Baca langsung kolom due_date (tanpa lewat cast model): kolom date
 * menyimpan 'Y-m-d 00:00:00' di sqlite dan 'Y-m-d' di MySQL, jadi bandingkan
 * 10 karakter pertama supaya portabel.
 */
    private function assertDueDateStored(int $invoiceId, string $expected): void
    {
        $raw = \Illuminate\Support\Facades\DB::table('invoices')
            ->where('id', $invoiceId)
            ->value('due_date');

        $this->assertNotNull($raw, 'Baris invoice tidak ditemukan di DB.');
        $this->assertSame($expected, substr($raw, 0, 10), 'Kolom due_date tidak menyimpan tanggal yang benar.');
    }

    public function test_custom_date_from_calendar_is_stored_unchanged(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        // Tanggal yang tidak cocok preset mana pun — hasil klik kalender.
        $custom = now()->addDays(23)->toDateString();

        $this->post(route('invoices.store'), $this->payload($client, $custom))
            ->assertSessionHas('success');

        $this->assertDueDateStored(Invoice::latest('id')->firstOrFail()->id, $custom);
    }

    public function test_preset_date_is_stored_on_update(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $invoice = InvoiceFactory::new()->withItems()->create(['client_id' => $client->id]);
        $expected = now()->addDays(45)->toDateString();

        $this->put(route('invoices.update', $invoice), $this->payload($client, $expected))
            ->assertSessionHas('success');

        $this->assertSame($expected, $invoice->fresh()->due_date->toDateString());
    }
}