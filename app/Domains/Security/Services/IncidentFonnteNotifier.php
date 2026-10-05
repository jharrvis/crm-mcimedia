<?php

namespace App\Domains\Security\Services;

use App\Domains\Hestia\Enums\DiskAlertLevel;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Security\Models\SecurityIncident;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Layanan pengiriman notifikasi WhatsApp via Fonnte untuk insiden keamanan.
 * Pola mirip SendInvoiceWhatsappJob tapi untuk insiden.
 */
class IncidentFonnteNotifier
{
    /**
     * Kirim notifikasi WA untuk insiden baru (P1/Critical).
     */
    public function notifyP1Created(SecurityIncident $incident): void
    {
        $this->sendIfEnabled($incident, 'p1_created', $this->buildP1Message($incident));
    }

    /**
     * Kirim notifikasi WA untuk eskalasi P1 -> P2 (15 menit).
     */
    public function notifyP2Escalated(SecurityIncident $incident): void
    {
        $this->sendIfEnabled($incident, 'p2_escalated', $this->buildP2Message($incident));
    }

    /**
     * Kirim notifikasi WA untuk eskalasi major (30 menit).
     */
    public function notifyMajorEscalated(SecurityIncident $incident): void
    {
        $this->sendIfEnabled($incident, 'major_escalated', $this->buildMajorMessage($incident));
    }

    /**
     * Kirim notifikasi WA untuk recovery (UP).
     */
    public function notifyRecovered(SecurityIncident $incident): void
    {
        $this->sendIfEnabled($incident, 'recovered', $this->buildRecoveredMessage($incident));
    }

    /**
     * Kirim notifikasi WA untuk flapping detection.
     */
    public function notifyFlapping(SecurityIncident $incident): void
    {
        $this->sendIfEnabled($incident, 'flapping', $this->buildFlappingMessage($incident));
    }

    /**
     * Kirim notifikasi WA untuk alert kuota disk (t_afef420a).
     */
    public function notifyDiskQuotaAlert(SecurityIncident $incident, HestiaAccount $account, DiskAlertLevel $level): void
    {
        $percent = $account->diskUsagePercent() ?? 0;
        $server = $account->server?->name ?? $account->server?->host ?? 'server tidak diketahui';
        $emoji = $level === DiskAlertLevel::Critical ? '🔴' : '⚠️';
        $crmUrl = $this->getIncidentUrl($incident);

        $message = implode("\n", [
            "{$emoji} *ALERT KUOTA DISK — {$level->label()}*",
            "",
            "🌐 Website: {$account->domain}",
            "🖥️ Server: {$server} (user: {$account->hestia_user})",
            "📊 Pemakaian: {$account->disk_used} MB / {$account->disk_quota} MB ({$percent}%)",
            "Ambang: warning ".(int) config('crm.disk_quota.warning_percent', 80)."%, kritis ".(int) config('crm.disk_quota.critical_percent', 90)."%",
            "",
            "🔗 Detail di CRM: {$crmUrl}",
            "",
            $level === DiskAlertLevel::Critical
                ? 'Kuota hampir habis — segera bersihkan file atau upgrade paket.'
                : 'Mohon pantau pemakaian disk agar tidak melewati batas kritis.',
        ]);

        $this->sendIfEnabled($incident, 'disk_quota_'.$level->value, $message);
    }

    /**
     * Bangun pesan untuk insiden P1 baru.
     */
    private function buildP1Message(SecurityIncident $incident): string
    {
        $domain = $this->extractDomain($incident->title);
        $downTime = $incident->occurred_at->format('d/m/Y H:i:s');
        $crmUrl = $this->getIncidentUrl($incident);

        return implode("\n", [
            "🚨 *INCIDENT P1 - CRITICAL*",
            "",
            "Website: {$domain}",
            "Waktu DOWN: {$downTime}",
            "Monitor: {$incident->external_id}",
            "",
            "🔗 Detail di CRM: {$crmUrl}",
            "",
            "Tim DevOps telah diberitahu. Mohon segera cek.",
        ]);
    }

    /**
     * Bangun pesan untuk eskalasi P2.
     */
    private function buildP2Message(SecurityIncident $incident): string
    {
        $domain = $this->extractDomain($incident->title);
        $downTime = $incident->occurred_at->format('d/m/Y H:i:s');
        $duration = $incident->occurred_at->diffForHumans(now(), true);
        $crmUrl = $this->getIncidentUrl($incident);

        return implode("\n", [
            "⚠️ *INCIDENT ESCALATED TO P2*",
            "",
            "Website: {$domain}",
            "Waktu DOWN: {$downTime}",
            "Durasi: {$duration} (lebih dari 15 menit)",
            "Severity naik: Critical → High",
            "",
            "🔗 Detail di CRM: {$crmUrl}",
            "",
            "Membutuhkan perhatian segera!",
        ]);
    }

    /**
     * Bangun pesan untuk eskalasi major.
     */
    private function buildMajorMessage(SecurityIncident $incident): string
    {
        $domain = $this->extractDomain($incident->title);
        $eventTime = $incident->occurred_at->format('d/m/Y H:i:s');
        $duration = $incident->occurred_at->diffForHumans(now(), true);
        $crmUrl = $this->getIncidentUrl($incident);
        $isNetdata = str_contains(strtolower($incident->title), 'netdata');

        if ($isNetdata) {
            return implode("\n", [
                "🔴 *INCIDENT MAJOR - ESCALATED*",
                "",
                "🖥️ Server: {$domain}",
                "⚠️ Jenis: Alarm Netdata kritis",
                "📋 Detail: {$incident->title}",
                "Waktu mulai: {$eventTime}",
                "Durasi: {$duration} (lebih dari 30 menit)",
                "",
                "🔗 Detail di CRM: {$crmUrl}",
                "",
                "ESKALASI TINGKAT TINGGI - Butuh intervensi langsung!",
            ]);
        }

        return implode("\n", [
            "🔴 *INCIDENT MAJOR - ESCALATED*",
            "",
            "🌐 Website: {$domain}",
            "Waktu DOWN: {$eventTime}",
            "Durasi: {$duration} (lebih dari 30 menit)",
            "Status: IS_MAJOR = true",
            "",
            "🔗 Detail di CRM: {$crmUrl}",
            "",
            "ESKALASI TINGKAT TINGGI - Butuh intervensi langsung!",
        ]);
    }

    /**
     * Bangun pesan untuk recovery.
     */
    private function buildRecoveredMessage(SecurityIncident $incident): string
    {
        $domain = $this->extractDomain($incident->title);
        $downTime = $incident->occurred_at->format('d/m/Y H:i:s');
        $resolvedTime = $incident->resolved_at?->format('d/m/Y H:i:s') ?? 'now';
        $duration = $incident->occurred_at->diffForHumans($incident->resolved_at ?? now(), true);
        $crmUrl = $this->getIncidentUrl($incident);

        return implode("\n", [
            "✅ *INCIDENT RECOVERED*",
            "",
            "Website: {$domain}",
            "Waktu DOWN: {$downTime}",
            "Waktu UP: {$resolvedTime}",
            "Durasi downtime: {$duration}",
            "",
            "🔗 Detail di CRM: {$crmUrl}",
            "",
            "Website telah pulih. Terima kasih.",
        ]);
    }

    /**
     * Bangun pesan untuk flapping.
     */
    private function buildFlappingMessage(SecurityIncident $incident): string
    {
        $domain = $this->extractDomain($incident->title);
        $crmUrl = $this->getIncidentUrl($incident);

        return implode("\n", [
            "🔄 *FLAPPING DETECTED*",
            "",
            "Website: {$domain}",
            "Flap count: {$incident->flap_count}",
            "Status: is_flapping = true",
            "",
            "🔗 Detail di CRM: {$crmUrl}",
            "",
            "Website naik turun berulang. Perlu investigasi root cause.",
        ]);
    }

    /**
     * Ekstrak domain dari title insiden.
     */
    private function extractDomain(string $title): string
    {
        if (preg_match('/\[([^\]]+)\]/', $title, $matches)) {
            return $matches[1];
        }
        return 'unknown';
    }

    /**
     * Dapatkan URL insiden - coba route, fallback ke manual.
     */
    private function getIncidentUrl(SecurityIncident $incident): string
    {
        try {
            // Coba route named jika ada
            return route('security.incidents.show', $incident);
        } catch (\Throwable) {
            // Fallback ke URL manual
            $baseUrl = config('app.url') ?: URL::to('/');
            return rtrim($baseUrl, '/') . "/security/incidents/{$incident->id}";
        }
    }

    /**
     * Kirim pesan WA jika Fonnte aktif dan target ada.
     */
    private function sendIfEnabled(SecurityIncident $incident, string $level, string $message): void
    {
        $enabled = (bool) config('crm.fonnte.enabled');
        $token = (string) config('crm.fonnte.token');
        $target = (string) config('crm.fonnte.target'); // Nomor WA tujuan, bisa dari config atau env

        if (! $enabled || blank($token) || blank($target)) {
            Log::info('WA notifikasi insiden dilewati: Fonnte nonaktif/token kosong/target kosong.', [
                'incident_id' => $incident->id,
                'level' => $level,
            ]);
            return;
        }

        // Cek apakah sudah pernah kirim untuk level ini (anti-spam)
        if ($incident->hasWaNotified($level)) {
            Log::info('WA notifikasi insiden dilewati: sudah terkirim untuk level ini.', [
                'incident_id' => $incident->id,
                'level' => $level,
            ]);
            return;
        }

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->timeout(10)
            ->post((string) config('crm.fonnte.endpoint', 'https://api.fonnte.com/send'), [
                'target' => $target,
                'message' => $message,
            ]);

        if ($response->failed()) {
            Log::warning('Pengiriman WA notifikasi insiden gagal di Fonnte.', [
                'incident_id' => $incident->id,
                'level' => $level,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return;
        }

        // Tandai sudah terkirim
        $incident->markWaNotified($level);

        Log::info('WA notifikasi insiden terkirim.', [
            'incident_id' => $incident->id,
            'level' => $level,
            'target' => $target,
        ]);
    }
}