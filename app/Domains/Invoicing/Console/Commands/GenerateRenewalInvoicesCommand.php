<?php

namespace App\Domains\Invoicing\Console\Commands;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
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
            ->with('client')
            ->orderBy('end_date')
            ->get();

        $openStatuses = [InvoiceStatus::Draft, InvoiceStatus::Sent, InvoiceStatus::Overdue];
        $created = 0;

        foreach ($services as $service) {
            // Lewati bila masih ada invoice terbuka (draf/terkirim/terlambat) untuk layanan ini.
            $hasOpen = Invoice::where('service_id', $service->id)
                ->whereIn('status', $openStatuses)
                ->exists();

            if ($hasOpen) {
                continue;
            }

            DB::transaction(function () use ($service) {
                $invoice = Invoice::create([
                    'client_id' => $service->client_id,
                    'service_id' => $service->id,
                    'number' => InvoiceNumber::next(),
                    'title' => 'Perpanjangan '.$service->name,
                    'issue_date' => Carbon::today(),
                    'due_date' => $service->end_date,
                    'status' => InvoiceStatus::Draft,
                ]);

                $invoice->items()->create([
                    'description' => $service->name,
                    'quantity' => 1,
                    'unit_price' => $service->price,
                    'amount' => $service->price,
                    'sort_order' => 0,
                ]);

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
}
