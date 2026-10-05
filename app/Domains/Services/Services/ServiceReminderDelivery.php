<?php

namespace App\Domains\Services\Services;

use App\Domains\Invoicing\Services\InvoiceDelivery;
use App\Domains\Services\Enums\ServiceReminderKind;
use App\Domains\Services\Mail\ServiceReminderMailable;
use App\Domains\Services\Models\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Pengiriman reminder WhatsApp perpanjangan layanan (t_cc560a11) lewat
 * Fonnte — pola F2-6 (`InvoiceReminderDelivery`): nomor WA klien dinormalisasi
 * lewat `InvoiceDelivery::normalizeWhatsapp`, konfigurasi Fonnte yang sama.
 *
 * Bila Fonnte nonaktif/token kosong, atau klien tidak punya nomor WA:
 * pengiriman dilewati (skip) + peringatan log, tanpa exception dan tanpa
 * baris `service_reminders`, sehingga percobaan berikutnya tetap jalan.
 */
class ServiceReminderDelivery
{
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_EMAIL = 'email';

    /**
     * Channel yang dijalankan scheduler harian: WhatsApp saja. Email hanya
     * lewat tombol manual halaman Pengingat (t_6f78ca1d) supaya perilaku
     * batch `crm:send-service-reminders` tidak berubah.
     *
     * @return array<int, string>
     */
    public static function channels(): array
    {
        return [self::CHANNEL_WHATSAPP];
    }

    /**
     * Kirim reminder layanan via email (tombol manual halaman Pengingat).
     * True bila benar-benar terkirim; false bila dilewati/gagal — tanpa
     * exception dan tanpa perubahan data.
     */
    public function sendEmail(Service $service, ServiceReminderKind $kind): bool
    {
        $email = $service->client?->email;

        if (blank($email)) {
            Log::warning('Reminder email layanan dilewati: klien tidak punya alamat email.', [
                'service' => $service->name,
                'kind' => $kind->value,
            ]);

            return false;
        }

        try {
            Mail::to($email)->send(new ServiceReminderMailable($service, $kind));
        } catch (\Throwable $e) {
            // SMTP/gagal kirim: jangan lempar exception ke request admin —
            // cukup balik error lewat flash message.
            Log::warning('Reminder email layanan gagal dikirim.', [
                'service' => $service->name,
                'kind' => $kind->value,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Kirim reminder layanan via WhatsApp. True bila benar-benar terkirim;
     * false bila dilewati/gagal — tanpa exception dan tanpa perubahan data.
     */
    public function sendWhatsapp(Service $service, ServiceReminderKind $kind): bool
    {
        $enabled = (bool) config('crm.fonnte.enabled');
        $token = (string) config('crm.fonnte.token');

        if (! $enabled || blank($token)) {
            Log::warning('Reminder WhatsApp layanan dilewati: Fonnte nonaktif atau token kosong.', [
                'service' => $service->name,
                'kind' => $kind->value,
            ]);

            return false;
        }

        $target = InvoiceDelivery::normalizeWhatsapp($service->client?->whatsapp);

        if (blank($target)) {
            Log::warning('Reminder WhatsApp layanan dilewati: klien tidak punya nomor WhatsApp.', [
                'service' => $service->name,
                'kind' => $kind->value,
            ]);

            return false;
        }

        try {
            $response = Http::withHeaders(['Authorization' => $token])
                ->acceptJson()
                ->timeout(10)
                ->post((string) config('crm.fonnte.endpoint'), [
                    'target' => $target,
                    'message' => self::whatsappMessage($service, $kind),
                ]);
        } catch (ConnectionException $e) {
            // Fonnte tidak terjangkau (DNS/timeout): jangan melempar exception —
            // batch harian harus tetap jalan untuk layanan lain.
            Log::warning('Reminder WhatsApp layanan gagal: Fonnte tidak terjangkau.', [
                'service' => $service->name,
                'kind' => $kind->value,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Reminder WhatsApp layanan gagal di Fonnte.', [
                'service' => $service->name,
                'kind' => $kind->value,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Pesan WhatsApp reminder: nama layanan, jenis, tanggal berakhir, sisa /
     * keterlambatan hari, harga perpanjangan — gaya pesan invoice reminder.
     */
    public static function whatsappMessage(Service $service, ServiceReminderKind $kind): string
    {
        $business = config('crm.business.name');
        $contact = $service->client?->contact_name ?: $service->client?->name ?: 'Bapak/Ibu';
        $daysRemaining = $service->daysUntilEnd();

        $lines = [
            "Halo {$contact},",
            '',
            $kind === ServiceReminderKind::Overdue
                ? "Kabar dari {$business}: layanan berikut sudah melewati jatuh tempo:"
                : "Kabar dari {$business}: layanan berikut akan segera berakhir ({$kind->label()}):",
            "Layanan: {$service->name}",
            'Jenis: '.$service->type->label(),
            'Berakhir: '.tgl_id($service->end_date),
        ];

        if ($kind === ServiceReminderKind::Overdue) {
            $lines[] = 'Terlambat: '.abs($daysRemaining ?? 0).' hari';
        } elseif ($daysRemaining !== null) {
            $lines[] = "Sisa: {$daysRemaining} hari";
        }

        if ($service->price > 0) {
            $lines[] = 'Perpanjangan: '.rupiah($service->price);
        }

        $lines[] = '';
        $lines[] = $kind === ServiceReminderKind::Overdue
            ? 'Mohon segera diperpanjang agar layanan tidak terputus. Bila sudah diperpanjang, abaikan pesan ini.'
            : 'Untuk perpanjangan atau pertanyaan, silakan balas pesan ini. Terima kasih.';

        return implode("\n", $lines);
    }
}
