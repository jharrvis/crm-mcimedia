<?php

namespace App\Domains\Security\Services;

use App\Domains\Security\Models\SecurityIncident;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Layanan pembuatan kartu kanban otomatis untuk insiden P1.
 * Menulis langsung ke database kanban board mci-team.
 */
class IncidentKanbanCardCreator
{
    private string $kanbanDbPath;
    private string $boardSlug = 'mci-team';

    public function __construct()
    {
        $this->kanbanDbPath = base_path('../../.hermes/kanban/boards/mci-team/kanban.db');
        // Fallback jika path berbeda
        if (! file_exists($this->kanbanDbPath)) {
            $this->kanbanDbPath = '/home/ubuntu/.hermes/kanban/boards/mci-team/kanban.db';
        }
    }

    /**
     * Buat kartu kanban untuk insiden P1 baru.
     */
    public function createForP1Incident(SecurityIncident $incident): ?string
    {
        if (! file_exists($this->kanbanDbPath)) {
            Log::warning('Database kanban tidak ditemukan, kartu tidak dibuat.', [
                'incident_id' => $incident->id,
                'expected_path' => $this->kanbanDbPath,
            ]);
            return null;
        }

        $cardId = 'sec-' . $incident->id . '-' . Str::random(8);
        $title = $this->buildTitle($incident);
        $body = $this->buildBody($incident);

        try {
            $db = new \SQLite3($this->kanbanDbPath);
            $db->enableExceptions(true);

            $stmt = $db->prepare('
                INSERT INTO tasks (
                    id, title, body, assignee, status, priority, created_by, created_at,
                    workspace_kind, workspace_path, tenant, completion_contract
                ) VALUES (
                    :id, :title, :body, :assignee, :status, :priority, :created_by, :created_at,
                    :workspace_kind, :workspace_path, :tenant, :completion_contract
                )
            ');

            $now = time();
            $stmt->bindValue(':id', $cardId, SQLITE3_TEXT);
            $stmt->bindValue(':title', $title, SQLITE3_TEXT);
            $stmt->bindValue(':body', $body, SQLITE3_TEXT);
            $stmt->bindValue(':assignee', 'devops', SQLITE3_TEXT);
            $stmt->bindValue(':status', 'todo', SQLITE3_TEXT);
            $stmt->bindValue(':priority', 10, SQLITE3_INTEGER);
            $stmt->bindValue(':created_by', 'system', SQLITE3_TEXT);
            $stmt->bindValue(':created_at', $now, SQLITE3_INTEGER);
            $stmt->bindValue(':workspace_kind', 'scratch', SQLITE3_TEXT);
            $stmt->bindValue(':workspace_path', '', SQLITE3_TEXT);
            $stmt->bindValue(':tenant', '', SQLITE3_TEXT);
            $stmt->bindValue(':completion_contract', 'local-only', SQLITE3_TEXT);

            $stmt->execute();

            Log::info('Kartu kanban insiden P1 dibuat.', [
                'incident_id' => $incident->id,
                'kanban_card_id' => $cardId,
                'board' => $this->boardSlug,
            ]);

            return $cardId;

        } catch (\Throwable $e) {
            Log::error('Gagal membuat kartu kanban untuk insiden.', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Bangun judul kartu.
     */
    private function buildTitle(SecurityIncident $incident): string
    {
        $domain = $this->extractDomain($incident->title);
        return "[P1] {$domain} - Website DOWN";
    }

    /**
     * Bangun body kartu.
     */
    private function buildBody(SecurityIncident $incident): string
    {
        $domain = $this->extractDomain($incident->title);
        $monitorId = $this->extractMonitorId($incident->external_id);
        $downTime = $incident->occurred_at->format('Y-m-d H:i:s T');
        $crmUrl = $this->getIncidentUrl($incident);

        return implode("\n\n", [
            "## Insiden P1 (Critical) - Website Production DOWN",
            "",
            "**Monitor:** {$monitorId}",
            "**Domain:** {$domain}",
            "**Waktu DOWN:** {$downTime}",
            "**Insiden CRM:** #{$incident->id}",
            "**Link CRM:** {$crmUrl}",
            "",
            "---",
            "",
            "**Deskripsi:**",
            $incident->description ?? 'Tidak ada deskripsi.',
            "",
            "---",
            "",
            "**Action Required:**",
            "- [ ] Verifikasi status website",
            "- [ ] Investigasi root cause",
            "- [ ] Update status insiden di CRM",
            "- [ ] Komunikasi ke klien jika perlu",
        ]);
    }

    private function extractDomain(string $title): string
    {
        if (preg_match('/\[([^\]]+)\]/', $title, $matches)) {
            return $matches[1];
        }
        return 'unknown';
    }

    private function extractMonitorId(string $externalId): string
    {
        if (preg_match('/uptime-kuma:([^:]+):/', $externalId, $matches)) {
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
}