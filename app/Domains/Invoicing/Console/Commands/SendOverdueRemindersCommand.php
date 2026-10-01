<?php

namespace App\Domains\Invoicing\Console\Commands;

use App\Domains\Invoicing\Enums\InvoiceReminderKind;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceReminder;
use App\Domains\Invoicing\Services\InvoiceReminderDelivery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Pengingat invoice jatuh tempo (F2-6): untuk invoice berstatus `sent`/`overdue`
 * yang jatuh tempo tepat 1, 7, atau 14 hari lalu, kirim pengingat sopan via
 * email & WhatsApp (jalur F2-5) dan catat baris `invoice_reminders` per channel
 * yang benar-benar terkirim.
 *
 * Idempotent: baris yang sudah ada untuk (invoice, kind, channel) tidak dikirim
 * ulang, sehingga menjalankan command 2x pada hari yang sama tidak menggandakan
 * pengiriman. Command ini TIDAK mengubah status invoice.
 */
class SendOverdueRemindersCommand extends Command
{
    protected $signature = 'crm:send-overdue-reminders';

    protected $description = 'Kirim pengingat invoice jatuh tempo (H+1, H+7, H+14) via email & WhatsApp';

    public function handle(InvoiceReminderDelivery $delivery): int
    {
        $today = Carbon::today();
        $targetDates = array_map(
            fn (InvoiceReminderKind $kind): string => $today->copy()->subDays($kind->days())->toDateString(),
            InvoiceReminderKind::cases()
        );

        $invoices = Invoice::query()
            ->unpaid()
            ->with('client')
            ->where(function (Builder $query) use ($targetDates): void {
                foreach ($targetDates as $date) {
                    $query->orWhereDate('due_date', $date);
                }
            })
            ->orderBy('due_date')
            ->get();

        $rows = 0;

        foreach ($invoices as $invoice) {
            $kind = $this->kindFor($invoice, $today);

            if ($kind === null) {
                continue; // tidak persis H+1/H+7/H+14 (abaikan jam)
            }

            foreach (InvoiceReminderDelivery::channels() as $channel) {
                if ($this->alreadySent($invoice, $kind, $channel)) {
                    continue;
                }

                $sent = $channel === InvoiceReminderDelivery::CHANNEL_EMAIL
                    ? $delivery->sendEmail($invoice, $kind)
                    : $delivery->sendWhatsapp($invoice, $kind);

                if (! $sent) {
                    continue; // dilewati/gagal: tanpa baris reminder
                }

                InvoiceReminder::create([
                    'invoice_id' => $invoice->id,
                    'kind' => $kind->value,
                    'channel' => $channel,
                    'sent_at' => now(),
                ]);

                $rows++;
            }
        }

        if ($rows === 0) {
            $this->info('Tidak ada pengingat invoice jatuh tempo yang dikirim.');
        } else {
            $this->info("Pengingat invoice jatuh tempo dikirim: {$rows} pengiriman.");
        }

        return self::SUCCESS;
    }

    /** Kind yang cocok bila due_date persis $kind hari lalu (bandingkan tanggal saja). */
    private function kindFor(Invoice $invoice, Carbon $today): ?InvoiceReminderKind
    {
        foreach (InvoiceReminderKind::cases() as $kind) {
            if ($invoice->due_date?->isSameDay($today->copy()->subDays($kind->days()))) {
                return $kind;
            }
        }

        return null;
    }

    private function alreadySent(Invoice $invoice, InvoiceReminderKind $kind, string $channel): bool
    {
        return InvoiceReminder::query()
            ->where('invoice_id', $invoice->id)
            ->where('kind', $kind->value)
            ->where('channel', $channel)
            ->exists();
    }
}
