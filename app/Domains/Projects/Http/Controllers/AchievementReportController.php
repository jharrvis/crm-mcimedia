<?php

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Projects\Enums\ReportPeriod;
use App\Domains\Projects\Http\Requests\AchievementReportRequest;
use App\Domains\Projects\Models\AchievementReport;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\AchievementReportBuilder;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Laporan pencapaian project (F3-4): per project per periode (minggu/bulan).
 * Ringkasan otomatis dihitung dari data periode; narasi diisi manual.
 */
class AchievementReportController extends Controller
{
    public function index(Project $project)
    {
        return view('projects.reports.index', [
            'project' => $project,
            'reports' => $project->achievementReports()->with('author')->get(),
            'periods' => ReportPeriod::cases(),
        ]);
    }

    public function store(AchievementReportRequest $request, Project $project)
    {
        $validated = $request->validated();
        $type = ReportPeriod::from($validated['period_type']);
        $anchor = isset($validated['anchor']) && $validated['anchor'] !== ''
            ? Carbon::parse($validated['anchor'])
            : Carbon::today();

        $report = (new AchievementReportBuilder($project))->build($type, $anchor);

        // Narasi manual hanya ditimpa bila diisi — generate ulang tidak menghapusnya.
        if (array_key_exists('narrative', $validated) && trim((string) $validated['narrative']) !== '') {
            $report->narrative = $validated['narrative'];
        }
        $report->created_by = $request->user()->id;
        $report->save();

        return redirect()->route('projects.reports.show', [$project, $report])
            ->with('success', 'Laporan pencapaian dibuat dari data periode.');
    }

    public function show(Project $project, AchievementReport $report)
    {
        $this->ensureBelongsToProject($project, $report);

        $report->load('author');

        return view('projects.reports.show', compact('project', 'report'));
    }

    public function pdf(Project $project, AchievementReport $report)
    {
        $this->ensureBelongsToProject($project, $report);

        $filename = 'laporan-'.Str::slug($project->title).'-'.
            $report->period_start->format('Ymd').'-'.$report->period_end->format('Ymd').'.pdf';

        return Pdf::loadView('projects.reports.pdf', [
            'project' => $project->load('client'),
            'report' => $report->load('author'),
        ])->setPaper('a4', 'portrait')->download($filename);
    }

    public function destroy(Project $project, AchievementReport $report)
    {
        $this->ensureBelongsToProject($project, $report);

        $report->delete();

        return redirect()->route('projects.reports.index', $project)
            ->with('success', 'Laporan pencapaian dihapus.');
    }

    /** Laporan dari project lain -> 404 (route bersarang). */
    private function ensureBelongsToProject(Project $project, AchievementReport $report): void
    {
        abort_if($report->project_id !== $project->id, 404);
    }
}
