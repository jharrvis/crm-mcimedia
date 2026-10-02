<?php

/**
 * Smoke test F4-11 (invoice recurring) — dijalankan via artisan tinker/eval.
 *
 * Menguji alur nyata di luar PHPUnit: buat klien + layanan + paket recurring,
 * jalankan command berkali-kali untuk cek idempotensi, majukan siklus, lalu
 * pastikan invoice periode berikutnya benar-benar terbit.
 */

use App\Domains\Clients\Models\Client;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\RecurringPlan;
use App\Domains\Invoicing\Services\RecurringInvoiceGenerator;
use App\Domains\Services\Models\Service;
use Illuminate\Support\Carbon;

/**
 * Penghitung hasil memakai `static` lokal, bukan `global`: script ini dijalankan
 * lewat `artisan tinker --execute` yang mengeksekusi kode di dalam closure,
 * sehingga variabel global tidak terlihat satu sama lain dan tally selalu 0.
 */
function check(string $label, bool $cond, string $detail = ''): void
{
    static $ok = 0;
    static $fail = 0;

    if ($cond) {
        $ok++;
        echo "  OK   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}".($detail !== '' ? " — {$detail}" : '')."\n";
    }

    // Diperbarui tiap panggilan supaya tally selalu benar saat dibaca di luar.
    $GLOBALS['f411_ok'] = $ok;
    $GLOBALS['f411_fail'] = $fail;
}

$f411Ok = 0;
$f411Fail = 0;

Carbon::setTestNow(Carbon::create(2026, 10, 7, 9, 0, 0));

echo "\n== F4-11 smoke test @ 2026-10-07 ==\n";

$client = Client::create([
    'name' => 'Klien Smoke F4-11',
    'email' => 'smoke-f411@example.test',
    'status' => 'active',
]);

$service = Service::create([
    'client_id' => $client->id,
    'type' => 'hosting',
    'name' => 'Hosting Smoke bulanan',
    'price' => 450000,
    'billing_cycle' => 'monthly',
    'start_date' => '2026-09-01',
    'end_date' => '2027-09-01',
    'status' => 'active',
    'reminder_enabled' => true,
]);

// --- Siklus 3 bulan (quarterly) -------------------------------------------
$quarterly = RecurringPlan::create([
    'client_id' => $client->id,
    'service_id' => $service->id,
    'title' => 'Paket smoke 3 bulan',
    'cycle' => 'quarterly',
    'next_invoice_date' => '2026-10-01',
    'due_days' => 14,
    'active' => true,
    'auto_send' => true,
]);
$quarterly->items()->create(['description' => 'Hosting triwulan', 'quantity' => 1, 'unit_price' => 1350000, 'sort_order' => 0]);
$quarterly->update(['total' => 1350000]);

echo "\n-- siklus quarterly --\n";
$artisan = app()->make(Illuminate\Contracts\Console\Kernel::class);
$exit = Artisan::call('crm:generate-recurring-invoices');
echo trim(Artisan::output())."\n";

check('satu invoice terbit', $quarterly->invoices()->count() === 1, 'count='.$quarterly->invoices()->count());
$inv = $quarterly->invoices()->first();
check('periode 1 Okt – 31 Des 2026', $inv?->period_start?->toDateString() === '2026-10-01' && $inv?->period_end?->toDateString() === '2026-12-31', $inv ? $inv->period_start.' → '.$inv->period_end : 'no invoice');
check('total Rp 1.350.000', (int) $inv?->total === 1350000, 'total='.$inv?->total);
check('due_date = issue + 14 hari', $inv?->due_date?->toDateString() === '2026-10-21', (string) $inv?->due_date);
check('auto_send → status sent', $inv?->status->value === 'sent', $inv?->status->value);
check('next_invoice_date maju ke 2027-01-01', $quarterly->fresh()->next_invoice_date->toDateString() === '2027-01-01', (string) $quarterly->fresh()->next_invoice_date);
check('nomor invoice format INV-YYYYMM-####', (bool) preg_match('/^INV-202610-\d{4}$/', (string) $inv?->number), (string) $inv?->number);
check('paket tertaut layanan', (bool) $inv?->services()->where('services.id', $service->id)->exists());

// Idempotensi: jalankan command 3x lagi di hari yang sama.
Artisan::call('crm:generate-recurring-invoices');
Artisan::call('crm:generate-recurring-invoices');
check('3x command ulang tidak menggandakan invoice', $quarterly->fresh()->invoices()->count() === 1, 'count='.$quarterly->fresh()->invoices()->count());

// Generate langsung via service pun idempoten.
$gen = app(RecurringInvoiceGenerator::class);
check('generateForPlan langsung juga null (sudah terbit)', $gen->generateForPlan($quarterly->fresh()) === null);

// --- Majukan ke siklus berikutnya (1 Jan 2027) ------------------------------
echo "\n-- periode berikutnya (2027-01-01) --\n";
Carbon::setTestNow(Carbon::create(2027, 1, 1, 7, 0, 0));
Artisan::call('crm:generate-recurring-invoices');
$inv2 = $quarterly->fresh()->invoices()->orderByDesc('id')->first();
check('invoice kedua terbit', $quarterly->fresh()->invoices()->count() === 2, 'count='.$quarterly->fresh()->invoices()->count());
check('periode 2 1 Jan – 31 Mar 2027', $inv2?->period_start?->toDateString() === '2027-01-01' && $inv2?->period_end?->toDateString() === '2027-03-31', $inv2 ? $inv2->period_start.' → '.$inv2->period_end : 'none');
check('next_invoice_date maju ke 2027-04-01', $quarterly->fresh()->next_invoice_date->toDateString() === '2027-04-01', (string) $quarterly->fresh()->next_invoice_date);
check('nomor berbeda dari periode pertama', $inv?->number !== $inv2?->number, $inv?->number.' vs '.$inv2?->number);

// --- Bulanan, siklus pendek, draf ------------------------------------------
echo "\n-- siklus monthly (draf, tanpa service) --\n";
$monthly = RecurringPlan::create([
    'client_id' => $client->id,
    'title' => 'Paket smoke bulanan',
    'cycle' => 'monthly',
    'next_invoice_date' => '2026-10-01',
    'due_days' => 7,
    'active' => true,
    'auto_send' => false,
]);
$monthly->items()->create(['description' => 'Domain', 'quantity' => 2, 'unit_price' => 125000, 'sort_order' => 0]);
$monthly->update(['total' => 250000]);

Carbon::setTestNow(Carbon::create(2026, 10, 7, 9, 0, 0));
Artisan::call('crm:generate-recurring-invoices', ['--cycle' => 'monthly']);
$mInv = $monthly->fresh()->invoices()->first();
check('invoice bulanan terbit', $mInv !== null);
check('periode 1–31 Okt 2026', $mInv?->period_start?->toDateString() === '2026-10-01' && $mInv?->period_end?->toDateString() === '2026-10-31', $mInv ? $mInv->period_start.' → '.$mInv->period_end : 'none');
check('total qty×harga = Rp 250.000', (int) $mInv?->total === 250000, 'total='.$mInv?->total);
check('status draft (auto_send=false)', $mInv?->status->value === 'draft', $mInv?->status->value);
check('due_date = issue + 7 hari', $mInv?->due_date?->toDateString() === '2026-10-14', (string) $mInv?->due_date);
check('tanpa layanan terkait', $mInv?->services()->count() === 0);
check('next = 2026-11-01', $monthly->fresh()->next_invoice_date->toDateString() === '2026-11-01', (string) $monthly->fresh()->next_invoice_date);

// --send memaksa terkirim meski paket draf.
Carbon::setTestNow(Carbon::create(2026, 11, 1, 7, 0, 0));
Artisan::call('crm:generate-recurring-invoices', ['--send' => true]);
$mInv2 = $monthly->fresh()->invoices()->orderByDesc('id')->first();
check('--send membuat invoice terkirim', $mInv2?->status->value === 'sent', $mInv2?->status->value);

// --- Filter siklus tidak dikenal -------------------------------------------
echo "\n-- validasi command --\n";
$exit = Artisan::call('crm:generate-recurring-invoices', ['--cycle' => 'weekly']);
check('siklus tak dikenal ditolak (exit != 0)', $exit !== 0, "exit={$exit}");

// --- Anti double-billing dengan perintah perpanjangan ----------------------
echo "\n-- anti double-billing vs crm:generate-renewal-invoices --\n";
Carbon::setTestNow(Carbon::create(2026, 10, 7, 9, 0, 0));
$beforeRenewal = Invoice::count();
Artisan::call('crm:generate-renewal-invoices');
$newRenewals = Invoice::whereNotNull('recurring_plan_id')->count();
check('perpanjangan tidak membuat invoice untuk layanan ber-paket recurring', Invoice::count() === $beforeRenewal, 'delta='.(Invoice::count() - $beforeRenewal).' recurring_new='.$newRenewals);

// --- Hapus paket: invoice tetap ada ----------------------------------------
echo "\n-- hapus paket --\n";
$invCountBefore = Invoice::count();
$quarterly->delete();
check('invoice tidak ikut terhapus', Invoice::count() === $invCountBefore, 'before='.$invCountBefore.' after='.Invoice::count());
check('recurring_plan_id jadi NULL (SET NULL)', Invoice::whereNull('recurring_plan_id')->count() >= 2);
$orphan = Invoice::whereNotNull('recurring_cycle')->first();
check('jejak siklus tetap ada di invoice', $orphan?->recurring_cycle !== null);
check('isRecurring() tetap true walau paket hilang', $orphan !== null && $orphan->isRecurring());

// --- Ringkasan ------------------------------------------------------------
$ok = $GLOBALS['f411_ok'] ?? 0;
$fail = $GLOBALS['f411_fail'] ?? 0;

echo "\n== RINGKASAN: {$ok} ok, {$fail} gagal ==\n";

// Bersihkan data smoke.
Client::where('id', $client->id)->delete();
Invoice::where('client_id', $client->id)->delete();
Service::where('client_id', $client->id)->delete();
RecurringPlan::where('client_id', $client->id)->delete();

Carbon::setTestNow();

exit($fail === 0 ? 0 : 1);