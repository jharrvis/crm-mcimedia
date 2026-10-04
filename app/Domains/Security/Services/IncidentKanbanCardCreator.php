<?php

namespace App\Domains\Security\Services;

use App\Domains\Security\Models\SecurityIncident;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Layanan pembuatan kartu kanban otomatis untuk insiden P1.
 * Menulis langsung ke database kanban board mci-team.
 *
 * Catatan keamanan (t_db983e91): path board dibaca dari
 * config('crm.security.kanban_board_path') dan saat APP_ENV=testing
 * ditulis ke sandbox, tidak pernah ke board production.
 */
class IncidentKanbanCardCreator
{
    private string $kanbanDbPath;
    private string $boardSlug = 'mci-team';

    public function __construct()
    {
        $this->kanbanDbPath = $this->resolveDbPath();
    }

    /**
     * Resolve path board: sandbox saat testing, production sebaliknya.
     *
     * Sandbox dibuat dengan skema yang sama agar tes bisa menulis kartu
     * tanpa menyentuh board production.
     */
    private function resolveDbPath(): string
    {
        $productionPath = config('crm.security.kanban_board_path')
            ?: base_path('../../.hermes/kanban/boards/mci-team/kanban.db');

        // Jangan pernah pakai board production saat testing.
        if ($this->isTestEnvironment()) {
            $sandboxPath = config('crm.security.kanban_sandbox_path');
            // Fail-safe: sandbox kosong -> pakai default storage; tidak pernah
            // jatuh ke board production.
            if (! $sandboxPath) {
                $sandboxPath = storage_path('app/test-kanban-sandbox.db');
            }

            if ($sandboxPath === $productionPath) {
                throw new \RuntimeException(
                    'KANBAN_SANDBOX_PATH tidak boleh sama dengan KANBAN_BOARD_PATH '
                    . 'saat environment testing: kartu akan bocor ke board production.'
                );
            }

            $this->ensureSandbox($sandboxPath);
            return $sandboxPath;
        }

        // Fallback lama untuk deployment yang path-nya berbeda.
        if (! file_exists($productionPath)) {
            $fallback = '/home/ubuntu/.hermes/kanban/boards/mci-team/kanban.db';
            return file_exists($fallback) ? $fallback : $productionPath;
        }

        return $productionPath;
    }

    private function isTestEnvironment(): bool
    {
        return App::environment('testing') || app()->runningUnitTests();
    }

    /**
     * Pastikan file sandbox + skema ada. Idempoten.
     */
    private function ensureSandbox(string $path): void
    {
        if (file_exists($path)) {
            return;
        }

        @mkdir(dirname($path), 0o755, true);

        $db = new \SQLite3($path);
        $db->enableExceptions(true);
        $db->exec('CREATE TABLE IF NOT EXISTS tasks (
            id TEXT PRIMARY KEY,
            title TEXT,
            body TEXT,
            assignee TEXT,
            status TEXT,
            priority INTEGER,
            created_by TEXT,
            created_at INTEGER,
            workspace_kind TEXT,
            workspace_path TEXT,
            tenant TEXT,
            completion_contract TEXT
        )');
        $db->close();
    }

    /**
     * Buat kartu kanban untuk insiden P1 baru.
     */
    public function createForP1Incident(SecurityIncident $incident): ?string
    {
        // Idempotency guard: jangan buat kartu ganda untuk insiden yang sama.
        $existing = $this->findOpenCardForIncident($incident->id);
        if ($existing !== null) {
            Log::info('Kartu kanban untuk insiden ini sudah ada, dilewati.', [
                'incident_id' => $incident->id,
                'kanban_card_id' => $existing,
                'board' => $this->boardSlug,
            ]);
            return $existing;
        }

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
     * Cek apakah sudah ada kartu open untuk incident_id ini (idempotency).
     *
     * Mencegah kartu ganda saat re-open insiden atau retry webhook.
     */
    private function findOpenCardForIncident(int $incidentId): ?string
    {
        if (! file_exists($this->kanbanDbPath)) {
            return null;
        }

        try {
            $db = new \SQLite3($this->kanbanDbPath);
            $db->enableExceptions(true);

            $stmt = $db->prepare('SELECT id FROM tasks
                WHERE body LIKE :pattern
                AND status IN (\'todo\', \'ready\', \'running\', \'blocked\')
                ORDER BY created_at DESC LIMIT 1');
            // Format body: "**Insiden CRM:** #<id>\n" — newline akhir mencegah
            // #1 cocok dengan #10, #11, dst.
            $stmt->bindValue(':pattern', "%Insiden CRM:** #{$incidentId}\n%", SQLITE3_TEXT);
            $result = $stmt->execute();
            $id = $result->fetchArray(SQLITE3_ASSOC)['id'] ?? null;
            $db->close();

            return $id;
        } catch (\Throwable $e) {
            // Sandbox belum punya skema atau DB tidak bisa dibaca: anggap belum ada.
            Log::warning('Gagal mengecek kartu kanban yang ada, lanjut membuat baru.', [
                'incident_id' => $incidentId,
                'error' => $e->getMessage(),
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