<?php

namespace App\Domains\Security\Services;

use App\Domains\Core\Models\ActivityLog;
use App\Domains\Security\Models\SecurityReport;

/**
 * Logika bersama pengiriman laporan keamanan ke klien (F4-3) yang dipakai
 * job email: penyusunan daftar penerima (email klien + email kontak),
 * alamat CC tetap, dan penandaan laporan terkirim setelah pengiriman berhasil.
 */
class SecurityReportDelivery
{
    /** Event activity log untuk riwayat pengiriman email laporan. */
    public const EVENT_EMAIL = 'email_sent';

    /**
     * Alamat CC tetap untuk setiap pengiriman laporan ke klien (default
     * info@mcimedia.net). Diatur lewat CRM_SECURITY_REPORT_CC_EMAIL; kosong
     * berarti tanpa CC.
     */
    public static function ccEmail(): ?string
    {
        $cc = config('crm.security.report_cc_email');

        return blank($cc) ? null : trim((string) $cc);
    }

    /**
     * Penerima email laporan: email utama klien + seluruh email kontak klien,
     * disaring (harus email valid), dibersihkan, dan diunifikasi.
     *
     * @return array<int, string>
     */
    public static function recipients(SecurityReport $report): array
    {
        $client = $report->client;

        if ($client === null) {
            return [];
        }

        $emails = [$client->email];

        foreach ($client->contacts as $contact) {
            $emails[] = $contact->email;
        }

        $cc = self::ccEmail();

        return collect($emails)
            ->map(fn ($email) => is_string($email) ? trim($email) : null)
            ->filter(fn ($email) => filled($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->reject(fn ($email) => $cc !== null && strcasecmp($email, $cc) === 0)
            ->unique(fn ($email) => strtolower($email))
            ->values()
            ->all();
    }

    /** Laporan hanya bisa dikirim bila punya berkas PDF dan setidaknya satu penerima. */
    public static function canSend(SecurityReport $report): bool
    {
        return $report->hasFile() && self::recipients($report) !== [];
    }

    /**
     * Setelah email berhasil dikirim: tandai laporan terkirim (status sent,
     * sent_at terisi) lalu catat event pengiriman ke activity log.
     *
     * @param  array<int, string>  $to
     */
    public static function markDelivered(SecurityReport $report, array $to, ?string $cc): void
    {
        $report->markSent();

        $description = "Laporan keamanan {$report->period} dikirim via email ke ".implode(', ', $to);
        $description .= filled($cc) ? " (CC {$cc})." : '.';

        ActivityLog::record($report, self::EVENT_EMAIL, $description);
    }
}
