<?php

namespace App\Domains\Services\Console\Commands;

use App\Domains\Services\Enums\ServiceReminderKind;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Models\Service;
use App\Domains\Services\Models\ServiceReminder;
use App\Domains\Services\Services\ServiceReminderDelivery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Reminder WhatsApp perpanjangan layanan (t_cc560a11): layanan aktif dengan
 * `reminder_enabled` yang berakhir tepat H-7/H-3/H-1, plus yang sudah lewat
 * jatuh tempo, dikirim WA ke klien (Fonnte) dan dicatat ke `service_reminders`
 * per channel yang benar-benar terkirim.
 *
 * Idempotent: baris yang sudah ada untuk (layanan, kind, channel) tidak
 * dikirim ulang, sehingga menjalankan command 2x pada hari yang sama tidak
 * menggandakan pengiriman. Klaim baris terjadi SEBELUM pengiriman (insert
 * dulu + unique index) sehingga dua run bersamaan tidak bisa mengirim ganda;
 * bila pengiriman gagal/dilewati klaim dilepas supaya dicoba lagi run
 * berikutnya. Kind overdue dikirim SEKALI (catch-up): begitu ada baris
 * overdue, layanan tidak lagi diingatkan meski terus lewat jatuh tempo.
 *
 * Command ini TIDAK mengubah status layanan maupun membuat invoice — draf
 * invoice perpanjangan sudah diurus `crm:generate-renewal-invoices` (07:30).
 */
class SendServiceRemindersCommand extends Command
{
    protected $signature = 'crm:send-service-reminders';

    protected $description = 'Kirim reminder WhatsApp perpanjangan layanan (H-7/H-3/H-1/overdue) ke klien';

    public function handle(ServiceReminderDelivery $delivery): int
    {
        $today = Carbon::today();

        // overdue: jangan muat SELURUH riwayat — hanya yang belum pernah
        // di-reminder overdue. H-7/H-3/H-1 ditarik per tanggalnya. chunkById
        // agar batch harian tetap ringan bila data layanan besar.
        $sent = 0;

        Service::query()
            ->where('status', ServiceStatus::Active)
            ->where('reminder_enabled', true)
            ->whereNotNull('end_date')
            ->with('client')
            ->where(function (Builder $query) use ($today): void {
                foreach ([7, 3, 1] as $days) {
                    $query->orWhereDate('end_date', $today->copy()->addDays($days));
                }
                $query->orWhereDate('end_date', '<', $today);
            })
            ->orderBy('end_date')
            ->chunkById(200, function ($services) use (&$sent, $today, $delivery): void {
                foreach ($services as $service) {
                    $sent += $this->remindService($service, $today, $delivery);
                }
            });

        if ($sent === 0) {
            $this->info('Tidak ada reminder layanan yang dikirim.');
        } else {
            $this->info("Reminder layanan dikirim: {$sent} pengiriman.");
        }

        return self::SUCCESS;
    }

    /**
     * Proses satu layanan: tentukan kind, kirim bila belum pernah, catat baris.
     * Return jumlah pengiriman yang berhasil (0/1 per channel).
     */
    private function remindService(Service $service, Carbon $today, ServiceReminderDelivery $delivery): int
    {
        $kind = $this->kindFor($service, $today);

        if ($kind === null) {
            return 0; // tidak persis H-7/H-3/H-1 dan belum overdue (aman bila jadwal berubah)
        }

        $sent = 0;

        foreach (ServiceReminderDelivery::channels() as $channel) {
            if ($this->alreadySent($service, $kind, $channel)) {
                continue;
            }

            // Klaim baris DULU sebelum kirim: unique (service_id, kind, channel)
            // memastikan hanya satu run yang boleh mengirim kind ini — dua run
            // bersamaan tidak bisa sama-sama lolos exists() lalu kirim ganda.
            try {
                $row = ServiceReminder::create([
                    'service_id' => $service->id,
                    'kind' => $kind->value,
                    'channel' => $channel,
                    'sent_at' => now(),
                ]);
            } catch (QueryException $e) {
                if ((string) ($e->errorInfo[0] ?? $e->getCode()) === '23000') {
                    continue; // sudah diklaim run lain → jangan kirim ulang
                }

                throw $e; // gangguan DB lain (deadlock/koneksi) jangan disembunyikan
            }

            if (! $delivery->sendWhatsapp($service, $kind)) {
                $row->delete(); // gagal/dilewati → lepas klaim, dicoba lagi run berikutnya

                continue;
            }

            $sent++;
        }

        // ponytail: bila proses mati tepat antara insert klaim & kirim (jarang),
        // satu reminder hilang — lebih baik kehilangan 1 pesan daripada spam
        // ganda ke klien. Upgrade path: kolom `sent_at` nullable + state
        // pending→sent bila perlu audit ketat.

        return $sent;
    }

    /**
     * Kind yang cocok: sisa hari persis 7/3/1, atau Overdue bila end_date
     * sudah lewat (bandingkan tanggal saja, abaikan jam).
     */
    private function kindFor(Service $service, Carbon $today): ?ServiceReminderKind
    {
        $end = $service->end_date;

        if ($end === null) {
            return null;
        }

        if ($end->isBefore($today)) {
            return ServiceReminderKind::Overdue;
        }

        return ServiceReminderKind::forDaysRemaining((int) $today->diffInDays($end, false));
    }

    private function alreadySent(Service $service, ServiceReminderKind $kind, string $channel): bool
    {
        return ServiceReminder::query()
            ->where('service_id', $service->id)
            ->where('kind', $kind->value)
            ->where('channel', $channel)
            ->exists();
    }
}
