<?php

namespace App\Domains\Invoicing\Console\Commands;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\RecurringPlan;
use App\Domains\Invoicing\Services\InvoiceNumber;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateRenewalInvoicesCommand extends Command
{
    protected $signature = 'crm:generate-renewal-invoices {--days=30 : Buat draf untuk layanan yang berakhir dalam N hari}';

    protected $description = 'Buat draf invoice perpanjangan untuk layanan aktif yang segera berakhir';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $services = Service::query()
            ->where('status', ServiceStatus::Active)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', Carbon::today())
            ->whereDate('end_date', '<=', Carbon::today()->addDays($days))
            ->with(['client', 'product', 'parent'])
            ->orderBy('end_date')
            ->get();

        $openStatuses = [InvoiceStatus::Draft, InvoiceStatus::Sent, InvoiceStatus::Overdue];
        $created = 0;

        // F4-11: layanan yang sudah diurus lewat paket recurring TIDAK dibuatkan
        // invoice perpanjangan di sini — tagihannya terbit dari paket (siklus
        // 1/3/6/12 bulan). Tanpa pengecualian ini satu layanan bisa ditagih dua
        // kali: sekali oleh paket recurring, sekali oleh perintah ini.
        $recurringServiceIds = RecurringPlan::query()
            ->active()
            ->whereNotNull('service_id')
            ->pluck('service_id')
            ->all();

        // Kelompokkan layanan yang lolos filter ke satu tagihan per domain:
        // domain + subdomain-nya (rantai parent F4-9) + layanan lain yang
        // mereferensikan host sama (mis. hosting untuk domain yang sama) masuk
        // invoice yang sama, bukan satu invoice per baris.
        $groups = [];

        foreach ($services as $service) {
            if (in_array($service->id, $recurringServiceIds, true)) {
                continue;
            }

            // Lewati bila layanan ini sudah tercakup invoice terbuka (draf/terkirim/
            // terlambat) — baik invoice tunggal maupun invoice gabungan.
            $hasOpen = Invoice::whereHas('services', fn ($q) => $q->where('services.id', $service->id))
                ->whereIn('status', $openStatuses)
                ->exists();

            if ($hasOpen) {
                continue;
            }

            $groups[$service->client_id.'|'.$this->groupKey($service)][] = $service;
        }

        foreach ($groups as $group) {
            // Induk dulu, lalu urut tanggal berakhir supaya item & nomor
            // deterministik antar-run.
            usort($group, fn (Service $a, Service $b) => [$a->parent_id === null ? 0 : 1, $a->end_date, $a->id]
                <=> [$b->parent_id === null ? 0 : 1, $b->end_date, $b->id]);

            DB::transaction(function () use ($group) {
                $lead = $group[0];
                $label = $this->label($lead, $group);

                $invoice = Invoice::create([
                    'client_id' => $lead->client_id,
                    'number' => InvoiceNumber::next(),
                    'title' => 'Perpanjangan '.$label,
                    'issue_date' => Carbon::today(),
                    // Jatuh tempo = tanggal berakhir paling awal dalam grup.
                    'due_date' => collect($group)->min->end_date,
                    'status' => InvoiceStatus::Draft,
                ]);

                foreach ($group as $sort => $service) {
                    $invoice->services()->attach($service->id);

                    // Harga dari katalog produk (sales_price terkini) bila
                    // layanan tertaut produk; fallback snapshot `services.price`.
                    $unitPrice = $service->renewalUnitPrice();

                    $invoice->items()->create([
                        'description' => $service->name,
                        'quantity' => 1,
                        'unit_price' => $unitPrice,
                        'amount' => $unitPrice,
                        'sort_order' => $sort,
                    ]);
                }

                $invoice->recalculateTotals();
            });

            $created++;
        }

        if ($created === 0) {
            $this->info('Tidak ada draf invoice perpanjangan yang dibuat.');
        } else {
            $this->info("Draf invoice perpanjangan dibuat: {$created}");
        }

        return self::SUCCESS;
    }

    /**
     * Kunci pengelompokan se-domain (per klien): host dari domain induk
     * tingkat teratas — subdomain ikut mengelompok di bawah induknya, bukan
     * memakai host-nya sendiri. Bila tidak ada host valid (referensi kosong /
     * bukan domain), layanan berdiri sendiri lewat kunci id unik.
     */
    private function groupKey(Service $service): string
    {
        $root = $service->domainRoot();

        $host = Service::normalizeHost($root->reference ?: $root->name)
            ?? Service::normalizeHost($service->reference ?: $service->name);

        if ($host === null || ! str_contains($host, '.')) {
            return 'service:'.$service->id;
        }

        // `www.contoh.com` dan `contoh.com` adalah domain yang sama.
        return preg_replace('/^www\./', '', $host);
    }

    /** Label invoice: nama layanan tunggal, atau host domain untuk grup. */
    private function label(Service $lead, array $group): string
    {
        if (count($group) === 1) {
            return $lead->name;
        }

        return $lead->domainRoot()->reference ?: $lead->domainRoot()->name;
    }
}
