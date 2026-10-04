<?php

namespace App\Console\Commands;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Services\IncidentFonnteNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command eskalasi insiden keamanan.
 *
 * Dijalankan setiap menit via Laravel Scheduler.
 * Logika:
 * - P1 (critical) open > 15 menit -> naik ke P2 (high) + notifikasi WA
 * - P1/P2 open > 30 menit -> is_major=true + notifikasi WA
 */
class EscalateSecurityIncidentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crm:escalate-incidents';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Eskalasi insiden keamanan: P1->P2 setelah 15 menit, is_major setelah 30 menit';

    public function __construct(
        private readonly IncidentFonnteNotifier $fonnteNotifier,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $escalatedCount = 0;
        $majorCount = 0;

        // 1. Eskalasi P1 -> P2 (open > 15 menit, belum major)
        $p1Incidents = SecurityIncident::query()
            ->where('status', IncidentStatus::Open)
            ->where('severity', IncidentSeverity::Critical)
            ->where('occurred_at', '<=', now()->subMinutes(15))
            ->where('is_major', false)
            ->get();

        foreach ($p1Incidents as $incident) {
            $this->escalateToP2($incident);
            $escalatedCount++;
        }

        // 2. Set is_major untuk P1/P2 open > 30 menit
        $majorIncidents = SecurityIncident::query()
            ->where('status', IncidentStatus::Open)
            ->whereIn('severity', [IncidentSeverity::Critical, IncidentSeverity::High])
            ->where('occurred_at', '<=', now()->subMinutes(30))
            ->where('is_major', false)
            ->get();

        foreach ($majorIncidents as $incident) {
            $this->markAsMajor($incident);
            $majorCount++;
        }

        $this->info("Eskalasi selesai: {$escalatedCount} insiden P1->P2, {$majorCount} insiden marked major.");

        return Command::SUCCESS;
    }

    /**
     * Eskalasi insiden dari P1 (critical) ke P2 (high).
     */
    private function escalateToP2(SecurityIncident $incident): void
    {
        $incident->update([
            'severity' => IncidentSeverity::High,
        ]);

        // Kirim notifikasi WA untuk eskalasi P2
        $this->fonnteNotifier->notifyP2Escalated($incident);

        Log::info('Insiden diekskalasi P1 -> P2', [
            'incident_id' => $incident->id,
            'client_id' => $incident->client_id,
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
        ]);

        $this->line("Eskalasi P1->P2: Insiden #{$incident->id} ({$incident->title})");
    }

    /**
     * Tandai insiden sebagai major (open > 30 menit).
     */
    private function markAsMajor(SecurityIncident $incident): void
    {
        $incident->update([
            'is_major' => true,
        ]);

        // Kirim notifikasi WA untuk eskalasi major
        $this->fonnteNotifier->notifyMajorEscalated($incident);

        Log::warning('Insiden ditandai MAJOR', [
            'incident_id' => $incident->id,
            'client_id' => $incident->client_id,
            'severity' => $incident->severity->value,
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
        ]);

        $this->warn("Major escalation: Insiden #{$incident->id} ({$incident->title})");
    }
}