<?php

namespace App\Domains\Projects\Support;

use App\Domains\Projects\Enums\ReportPeriod;
use App\Domains\Projects\Models\AchievementReport;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Enums\TaskStatus;
use Illuminate\Support\Carbon;

/**
 * Penyusun laporan pencapaian (F3-4).
 *
 * Ringkasan otomatis dihitung dari data periode:
 *  - task project yang selesai (completed_at di dalam rentang), dan
 *  - entri jurnal (occurred_on di dalam rentang) + jumlah per kategori.
 *
 * Dipakai oleh controller (generate manual) dan command
 * `crm:generate-achievement-reports` (generate otomatis), sehingga kelak
 * penjadwalan otomatis tidak perlu logika baru.
 */
class AchievementReportBuilder
{
    public function __construct(private readonly Project $project) {}

    /**
     * Buat/segarkan laporan untuk periode yang memuat $anchor.
     * Idempotent: memanggil dua kali untuk periode sama memperbarui laporan
     * yang sama (bukan menggandakan), narasi manual tidak diubah.
     */
    public function build(ReportPeriod $type, Carbon $anchor): AchievementReport
    {
        [$start, $end] = $type->rangeFor($anchor);

        // Kolom tanggal disimpan sebagai datetime tengah malam oleh cast 'date',
        // jadi bandingkan dengan whereDate agar pencarian periode tetap cocok.
        $report = $this->project->achievementReports()
            ->where('period_type', $type->value)
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->first();

        if ($report === null) {
            $report = new AchievementReport([
                'project_id' => $this->project->id,
                'period_type' => $type->value,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ]);
        }

        $report->summary = $this->summary($type, $start, $end);
        $report->generated_at = now();
        $report->save();

        return $report;
    }

    /**
     * Teks ringkasan otomatis (multi-baris) untuk periode $start..$end.
     */
    public function summary(ReportPeriod $type, Carbon $start, Carbon $end): string
    {
        $completed = $this->project->tasks()
            ->where('status', TaskStatus::Done)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->orderBy('completed_at')
            ->get();

        $journals = $this->project->journals()
            ->whereDate('occurred_on', '>=', $start->toDateString())
            ->whereDate('occurred_on', '<=', $end->toDateString())
            ->get();

        $lines = [];
        $lines[] = 'Periode: '.$type->rangeLabel($start, $end);
        $lines[] = '';
        $lines[] = "Task selesai: {$completed->count()}";
        foreach ($completed as $task) {
            $lines[] = '- '.$task->title.' (selesai '.$task->completed_at->format('d/m/Y').')';
        }
        if ($completed->isEmpty()) {
            $lines[] = '- (tidak ada task yang diselesaikan pada periode ini)';
        }

        $lines[] = '';
        $lines[] = 'Jurnal periode ini: '.$journals->count();
        foreach ($journals as $journal) {
            $lines[] = '- '.$journal->occurred_on->format('d/m/Y')
                .' ['.$journal->category->label().'] '
                .str_replace(["\r\n", "\n"], ' / ', trim($journal->body));
        }
        if ($journals->isEmpty()) {
            $lines[] = '- (tidak ada catatan jurnal pada periode ini)';
        }

        $blockers = $journals->filter(fn ($journal) => $journal->category->value === 'blocker')->count();
        if ($blockers > 0) {
            $lines[] = '';
            $lines[] = "Hambatan dicatat: {$blockers}";
        }

        return implode("\n", $lines);
    }
}
