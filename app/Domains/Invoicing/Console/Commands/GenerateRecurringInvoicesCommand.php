<?php

namespace App\Domains\Invoicing\Console\Commands;

use App\Domains\Invoicing\Enums\RecurringCycle;
use App\Domains\Invoicing\Models\RecurringPlan;
use App\Domains\Invoicing\Services\RecurringInvoiceGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Terbitkan invoice recurring (F4-11) untuk paket yang periodenya sudah jatuh
 * tempo. Terjadwal harian, idempoten per periode.
 */
class GenerateRecurringInvoicesCommand extends Command
{
    protected $signature = 'crm:generate-recurring-invoices
                            {--cycle= : Batasi ke satu siklus (monthly|quarterly|semiannual|yearly)}
                            {--send : Kirim sekalian (langsung berstatus terkirim), abaikan auto_send paket}';

    protected $description = 'Buat invoice otomatis untuk paket recurring yang periode berikutnya sudah jatuh tempo';

    public function handle(RecurringInvoiceGenerator $generator): int
    {
        $cycle = $this->option('cycle');

        if ($cycle !== null && ! RecurringCycle::tryFrom($cycle)) {
            $this->error("Siklus tidak dikenal: {$cycle}.");
            $this->line('Pilihan: '.implode(', ', array_column(RecurringCycle::cases(), 'value')).'.');

            return self::INVALID;
        }

        $plans = RecurringPlan::query()
            ->due()
            ->when($cycle, fn ($q) => $q->where('cycle', $cycle))
            ->with(['client', 'items'])
            ->orderBy('next_invoice_date')
            ->orderBy('id')
            ->get();

        // null = pakai setelan auto_send tiap paket; --send memaksa terkirim.
        $autoSend = $this->option('send') ? true : null;

        $created = 0;
        $skipped = 0;

        foreach ($plans as $plan) {
            try {
                $invoice = $generator->generateForPlan($plan, $autoSend);
            } catch (\Throwable $e) {
                // Satu paket bermasalah tidak boleh menghentikan sisanya.
                $skipped++;
                $this->error("Paket #{$plan->id} ({$plan->title}) gagal: {$e->getMessage()}");

                continue;
            }

            if ($invoice === null) {
                $skipped++;

                continue;
            }

            $created++;
            $this->line(sprintf(
                '%s ← %s (%s, periode %s s/d %s)',
                $invoice->number,
                $plan->title,
                $plan->cycle->shortLabel(),
                $invoice->period_start->format('d/m/Y'),
                $invoice->period_end->format('d/m/Y'),
            ));
        }

        $this->info(sprintf(
            'Invoice recurring: %d dibuat, %d dilewati (paket %d diperiksa).',
            $created,
            $skipped,
            $plans->count(),
        ));

        return self::SUCCESS;
    }
}