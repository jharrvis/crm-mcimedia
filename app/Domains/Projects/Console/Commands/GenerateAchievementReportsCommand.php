<?php

namespace App\Domains\Projects\Console\Commands;

use App\Domains\Projects\Enums\ReportPeriod;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\AchievementReportBuilder;
use App\Domains\Tasks\Enums\TaskStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Generate laporan pencapaian otomatis (F3-4).
 *
 * Untuk setiap project yang punya aktivitas pada periode (task selesai atau
 * entri jurnal), laporan dibuat/disegarkan memakai AchievementReportBuilder —
 * struktur yang sama dengan generate manual dari UI, sehingga penjadwalan
 * otomatis kelak tinggal menambahkan ->daily()/->weekly() di scheduler.
 *
 * Idempotent: menjalankan dua kali untuk periode yang sama tidak menggandakan
 * laporan (narasi manual yang sudah ada tidak dihapus).
 */
class GenerateAchievementReportsCommand extends Command
{
    protected $signature = 'crm:generate-achievement-reports
                            {--type=month : Periode laporan (week|month)}
                            {--date= : Tanggal acuan di dalam periode (YYYY-MM-DD, default hari ini)}
                            {--project= : Batasi hanya satu project (ID)}';

    protected $description = 'Buat/segarkan laporan pencapaian project dari data periode (F3-4)';

    public function handle(): int
    {
        $typeValue = (string) $this->option('type');

        if (! in_array($typeValue, ReportPeriod::values(), true)) {
            $this->error("Periode tidak dikenal: {$typeValue}. Pilih week atau month.");

            return self::FAILURE;
        }

        try {
            $anchor = $this->option('date')
                ? Carbon::parse((string) $this->option('date'))
                : Carbon::today();
        } catch (\Throwable $e) {
            $this->error('Tanggal acuan tidak valid.');

            return self::FAILURE;
        }

        $type = ReportPeriod::from($typeValue);
        [$start, $end] = $type->rangeFor($anchor);

        $query = Project::query()->orderBy('id');
        if ($this->option('project')) {
            $query->whereKey($this->option('project'));
        }

        $generated = 0;

        foreach ($query->get() as $project) {
            if (! $this->hasActivity($project, $start, $end)) {
                continue;
            }

            $report = (new AchievementReportBuilder($project))->build($type, $anchor);
            $generated++;
            $this->line("Laporan #{$report->id} — {$project->title} ({$report->periodLabel()})");
        }

        $this->info("Selesai. {$generated} laporan dibuat/disegarkan untuk {$type->rangeLabel($start, $end)}.");

        return self::SUCCESS;
    }

    /** Project dianggap beraktivitas bila ada task selesai atau jurnal pada periode itu. */
    private function hasActivity(Project $project, Carbon $start, Carbon $end): bool
    {
        $hasCompletedTask = $project->tasks()
            ->where('status', TaskStatus::Done)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->exists();

        if ($hasCompletedTask) {
            return true;
        }

        return $project->journals()
            ->whereDate('occurred_on', '>=', $start->toDateString())
            ->whereDate('occurred_on', '<=', $end->toDateString())
            ->exists();
    }
}
