<?php

namespace App\Domains\Services\Http\Controllers;

use App\Domains\Core\Models\ActivityLog;
use App\Domains\Services\Enums\ServiceReminderKind;
use App\Domains\Services\Models\Service;
use App\Domains\Services\Models\ServiceReminder;
use App\Domains\Services\Services\ServiceReminderDelivery;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Halaman Pengingat jatuh tempo layanan (t_6f78ca1d): daftar read-only +
 * tombol kirim manual per layanan (WhatsApp & Email). Pengiriman ulang
 * tidak di-block UI: anti-spam dijaga tabel `service_reminders` —
 * kind terjadwal (H-7/H-3/H-1/overdue) hanya terkirim SEKALI per channel,
 * kecuali kind `manual` (boleh berulang: satu baris di-update `sent_at`-nya
 * tiap kirim ulang).
 */
class ReminderController extends Controller
{
    public function __invoke(Request $request)
    {
        $days = 30;

        return view('reminders.index', [
            'days' => $days,
            'overdue' => Service::overdue()->with('client')->orderBy('end_date')->get(),
            'expiring' => Service::expiringSoon($days)->with('client')->orderBy('end_date')->get(),
        ]);
    }

    /** Kirim reminder manual via WhatsApp (Fonnte) ke klien pemilik layanan. */
    public function sendWhatsapp(Service $service, ServiceReminderDelivery $delivery)
    {
        $kind = $this->kindFor($service);

        if ($kind === null) {
            return back()->with('error', "Layanan {$service->name} tidak lagi butuh pengingat (sudah nonaktif atau tanggalnya di luar daftar).");
        }

        if ($this->duplicateBlocked($service, $kind, ServiceReminderDelivery::CHANNEL_WHATSAPP)) {
            return back()->with('error', "Reminder {$kind->label()} untuk {$service->name} sudah pernah dikirim via WhatsApp.");
        }

        if (! $delivery->sendWhatsapp($service, $kind)) {
            return back()->with('error', 'Pengiriman WhatsApp gagal — periksa konfigurasi Fonnte dan nomor WA klien.');
        }

        $this->record($service, $kind, ServiceReminderDelivery::CHANNEL_WHATSAPP);

        ActivityLog::record($service, 'reminder_whatsapp_sent', "Reminder {$kind->label()} layanan {$service->name} dikirim via WhatsApp.");

        return back()->with('success', "Reminder {$kind->label()} untuk {$service->name} dikirim via WhatsApp.");
    }

    /** Kirim reminder manual via email ke alamat klien pemilik layanan. */
    public function sendEmail(Service $service, ServiceReminderDelivery $delivery)
    {
        $kind = $this->kindFor($service);

        if ($kind === null) {
            return back()->with('error', "Layanan {$service->name} tidak lagi butuh pengingat (sudah nonaktif atau tanggalnya di luar daftar).");
        }

        if ($this->duplicateBlocked($service, $kind, ServiceReminderDelivery::CHANNEL_EMAIL)) {
            return back()->with('error', "Reminder {$kind->label()} untuk {$service->name} sudah pernah dikirim via email.");
        }

        if (! $delivery->sendEmail($service, $kind)) {
            return back()->with('error', 'Pengiriman email gagal — periksa alamat email klien dan konfigurasi mail.');
        }

        $this->record($service, $kind, ServiceReminderDelivery::CHANNEL_EMAIL);

        ActivityLog::record($service, 'reminder_email_sent', "Reminder {$kind->label()} layanan {$service->name} dikirim via email.");

        return back()->with('success', "Reminder {$kind->label()} untuk {$service->name} dikirim via email.");
    }

    /**
     * Kind untuk kirim manual: persis seperti scheduler (H-7/H-3/H-1 bila
     * sisa hari cocok, Overdue bila sudah lewat) — tapi bila sisa hari tidak
     * cocok satupun dan belum overdue, pakai `manual` supaya tombol tetap
     * bisa dipakai untuk layanan H-30/H-14/dll yang tampil di halaman.
     */
    private function kindFor(Service $service): ?ServiceReminderKind
    {
        if ($service->status->value !== 'active' || $service->end_date === null) {
            return null;
        }

        $days = (int) now()->startOfDay()->diffInDays($service->end_date->startOfDay(), false);

        if ($days < 0) {
            return ServiceReminderKind::Overdue;
        }

        return ServiceReminderKind::forDaysRemaining($days) ?? ServiceReminderKind::Manual;
    }

    /**
     * Kind terjadwal anti-duplikat; kind manual selalu boleh dikirim ulang
     * (admin sadar menekan tombol — `service_reminders` jadi jejak "terakhir
     * dikirim", bukan penghitung).
     */
    private function duplicateBlocked(Service $service, ServiceReminderKind $kind, string $channel): bool
    {
        if ($kind === ServiceReminderKind::Manual) {
            return false;
        }

        return ServiceReminder::query()
            ->where('service_id', $service->id)
            ->where('kind', $kind->value)
            ->where('channel', $channel)
            ->exists();
    }

    /**
     * Catat jejak pengiriman. Kind terjadwal: `create`, unique
     * (service_id, kind, channel) jadi penutup race klik ganda — pelanggaran
     * unique ditelan. Kind manual: `updateOrCreate` supaya `sent_at` selalu
     * mencerminkan pengiriman TERAKHIR (baris unique hanya boleh satu).
     */
    private function record(Service $service, ServiceReminderKind $kind, string $channel): void
    {
        if ($kind === ServiceReminderKind::Manual) {
            ServiceReminder::updateOrCreate(
                ['service_id' => $service->id, 'kind' => $kind->value, 'channel' => $channel],
                ['sent_at' => now()],
            );

            return;
        }

        try {
            ServiceReminder::create([
                'service_id' => $service->id,
                'kind' => $kind->value,
                'channel' => $channel,
                'sent_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ((string) ($e->errorInfo[0] ?? $e->getCode()) !== '23000') {
                throw $e;
            }
        }
    }
}
