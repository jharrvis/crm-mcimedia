<?php

namespace App\Domains\Quotation\Console\Commands;

use App\Domains\Core\Models\ActivityLog;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Jobs\SendQuotationEmailJob;
use App\Domains\Quotation\Jobs\SendQuotationWhatsappJob;
use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pengingat penawaran yang akan kedaluwarsa (Q-2, cermin pola F2-6):
 * untuk penawaran status `sent` dengan valid_until tepat 3 hari lagi, kirim
 * pengingat WA + email ke klien (berisi nomor, total, masa berlaku, tautan
 * publik) lewat job antrean yang sama seperti pengiriman biasa (F2-5).
 *
 * Sekali saja per penawaran: dijaga kolom `expiry_reminder_sent_at`
 * (bukan tabel baru). Command ini TIDAK mengubah status penawaran dan
 * TIDAK melakukan HTTP langsung — semuanya lewat queue. Hanya kanal yang
 * benar-benar bisa terkirim (ada alamatnya) yang diantrikan; pengingat
 * ditandai sudah terkirim bila minimal satu kanal berhasil diantrikan.
 */
class QuotationsExpiringCommand extends Command
{
    protected $signature = 'crm:quotations-expiring';

    protected $description = 'Kirim pengingat penawaran yang kedaluwarsa H-3 via email & WhatsApp';

    public function handle(): int
    {
        $target = Carbon::today()->addDays(3)->toDateString();

        $quotations = Quotation::query()
            ->where('status', QuotationStatus::Sent)
            ->whereNull('expiry_reminder_sent_at')
            ->whereDate('valid_until', $target)
            ->with('client')
            ->get();

        $queued = 0;

        foreach ($quotations as $quotation) {
            // Hanya antrekan kanal yang benar-benar bisa terkirim, mirip pola
            // invoice reminder: job dengan tujuan kosong akan skip + log
            // peringatan sehingga lebih baik tidak diantrikan sama sekali.
            // ponytail: kanal tanpa tujuan tidak ditandai 'sudah kirim', jadi
            // bila kontak/Fonnte diperbaiki sebelum valid_until berlalu, pengingat
            // masih terkirim pada run berikutnya; upgrade path = jejak per-kanal
            // seperti invoice_reminders bila perlu audit/retry per kanal.
            $channels = $this->deliverableChannels($quotation);

            if ($channels === []) {
                continue;
            }

            // Tautan publik wajib ada sebelum pengingat dikirim.
            QuotationDelivery::ensurePublicToken($quotation);

            if (in_array('email', $channels, true)) {
                SendQuotationEmailJob::dispatch($quotation);
            }

            if (in_array('whatsapp', $channels, true)) {
                SendQuotationWhatsappJob::dispatch($quotation);
            }

            $quotation->forceFill(['expiry_reminder_sent_at' => now()])->save();

            ActivityLog::record(
                $quotation,
                'expiry_reminder_sent',
                "Pengingat kedaluwarsa penawaran {$quotation->number} diantrekan ke klien via ".implode(' & ', $channels).'.'
            );

            $queued++;
        }

        if ($queued === 0) {
            $this->info('Tidak ada pengingat kedaluwarsa penawaran yang dikirim.');

            return self::SUCCESS;
        }

        $this->info("Pengingat kedaluwarsa penawaran dikirim: {$queued} penawaran.");

        return self::SUCCESS;
    }

    /**
     * Kanal pengiriman yang tersedia untuk klien ini: email butuh alamat,
     * WhatsApp butuh nomor + konfigurasi Fonnte aktif.
     *
     * @return list<string>
     */
    private function deliverableChannels(Quotation $quotation): array
    {
        $channels = [];

        if (! blank($quotation->client?->email)) {
            $channels[] = 'email';
        }

        if (
            ! blank($quotation->client?->whatsapp)
            && (bool) config('crm.fonnte.enabled', false)
            && ! blank(config('crm.fonnte.token'))
        ) {
            $channels[] = 'whatsapp';
        }

        return $channels;
    }
}
