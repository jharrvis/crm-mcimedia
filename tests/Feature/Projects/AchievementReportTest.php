<?php

namespace Tests\Feature\Projects;

use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Enums\ReportPeriod;
use App\Domains\Projects\Models\AchievementReport;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectJournal;
use App\Domains\Tasks\Enums\TaskStatus;
use App\Domains\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AchievementReportTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $project = Project::factory()->create();

        $this->get(route('projects.reports.index', $project))->assertRedirect(route('login'));
    }

    public function test_report_summary_is_generated_from_period_data(): void
    {
        $user = $this->login();
        $project = Project::factory()->create();

        Task::factory()->create([
            'project_id' => $project->id,
            'title' => 'Tulis artikel SEO #1',
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);
        // Task selesai di bulan lalu: tidak masuk ringkasan bulan ini.
        Task::factory()->create([
            'project_id' => $project->id,
            'title' => 'Tugas bulan lalu',
            'status' => TaskStatus::Done,
            'completed_at' => now()->subMonthNoOverflow()->startOfMonth(),
        ]);
        // Task belum selesai: bukan pencapaian.
        Task::factory()->create([
            'project_id' => $project->id,
            'title' => 'Tugas belum selesai',
            'status' => TaskStatus::InProgress,
            'completed_at' => null,
        ]);

        ProjectJournal::factory()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'occurred_on' => now()->toDateString(),
            'category' => 'progress',
            'body' => 'Riset kata kunci selesai.',
        ]);

        $response = $this->post(route('projects.reports.store', $project), [
            'period_type' => 'month',
            'anchor' => now()->toDateString(),
            'narrative' => 'Capaian bulan ini sesuai target.',
        ]);

        $report = AchievementReport::firstOrFail();
        $response->assertRedirect(route('projects.reports.show', [$project, $report]));

        $this->assertSame(ReportPeriod::Month, $report->period_type);
        $this->assertSame(now()->startOfMonth()->toDateString(), $report->period_start->toDateString());
        $this->assertSame(now()->endOfMonth()->toDateString(), $report->period_end->toDateString());
        $this->assertNotNull($report->generated_at);
        $this->assertSame($user->id, $report->created_by);

        $this->assertStringContainsString('Task selesai: 1', $report->summary);
        $this->assertStringContainsString('Tulis artikel SEO #1', $report->summary);
        $this->assertStringNotContainsString('Tugas bulan lalu', $report->summary);
        $this->assertStringNotContainsString('Tugas belum selesai', $report->summary);
        $this->assertStringContainsString('Jurnal periode ini: 1', $report->summary);
        $this->assertStringContainsString('Riset kata kunci selesai.', $report->summary);
        $this->assertSame('Capaian bulan ini sesuai target.', $report->narrative);
    }

    public function test_regenerating_same_period_does_not_duplicate_report(): void
    {
        $this->login();
        $project = Project::factory()->create();
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);

        $payload = ['period_type' => 'month', 'anchor' => now()->toDateString()];

        $this->post(route('projects.reports.store', $project), $payload)->assertRedirect();
        $this->post(route('projects.reports.store', $project), $payload)->assertRedirect();

        $this->assertSame(1, AchievementReport::count());

        // Baris baru untuk periode berbeda tetap dibuat.
        $this->post(route('projects.reports.store', $project), [
            'period_type' => 'month',
            'anchor' => now()->subMonthNoOverflow()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(2, AchievementReport::count());
    }

    public function test_week_period_covers_monday_to_sunday(): void
    {
        $this->login();
        $project = Project::factory()->create();
        $anchor = Carbon::parse('2026-10-07'); // Rabu

        ProjectJournal::factory()->create(['project_id' => $project->id, 'occurred_on' => '2026-10-04', 'body' => 'Entri minggu sebelumnya.']);
        ProjectJournal::factory()->create(['project_id' => $project->id, 'occurred_on' => '2026-10-05', 'body' => 'Entri Senin.']);
        ProjectJournal::factory()->create(['project_id' => $project->id, 'occurred_on' => '2026-10-11', 'body' => 'Entri Minggu.']);

        $this->post(route('projects.reports.store', $project), [
            'period_type' => 'week',
            'anchor' => $anchor->toDateString(),
        ])->assertRedirect();

        $report = AchievementReport::firstOrFail();
        $this->assertSame('2026-10-05', $report->period_start->toDateString());
        $this->assertSame('2026-10-11', $report->period_end->toDateString());
        $this->assertStringContainsString('Entri Senin.', $report->summary);
        $this->assertStringContainsString('Entri Minggu.', $report->summary);
        $this->assertStringNotContainsString('Entri minggu sebelumnya.', $report->summary);
    }

    public function test_manual_narrative_survives_regeneration(): void
    {
        $this->login();
        $project = Project::factory()->create();
        Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Done, 'completed_at' => now()]);

        $this->post(route('projects.reports.store', $project), [
            'period_type' => 'month',
            'anchor' => now()->toDateString(),
            'narrative' => 'Narasi asli.',
        ])->assertRedirect();

        // Generate ulang tanpa narasi.
        $this->post(route('projects.reports.store', $project), [
            'period_type' => 'month',
            'anchor' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(1, AchievementReport::count());
        $this->assertSame('Narasi asli.', AchievementReport::first()->narrative);
    }

    public function test_report_index_and_detail_page_render(): void
    {
        $this->login();
        $project = Project::factory()->create(['title' => 'Optimasi SEO qirana.com']);
        $report = AchievementReport::factory()->create([
            'project_id' => $project->id,
            'summary' => 'Task selesai: 3',
            'narrative' => 'Narasi laporan.',
        ]);

        $this->get(route('projects.reports.index', $project))
            ->assertOk()
            ->assertSee('Laporan pencapaian')
            ->assertSee($report->periodLabel())
            ->assertSee('Buat laporan');

        $this->get(route('projects.reports.show', [$project, $report]))
            ->assertOk()
            ->assertSee('Ringkasan otomatis')
            ->assertSee('Task selesai: 3')
            ->assertSee('Narasi laporan.')
            ->assertSee('Unduh PDF');
    }

    public function test_report_can_be_downloaded_as_pdf(): void
    {
        $this->login();
        $project = Project::factory()->create(['title' => 'Optimasi SEO qirana.com']);
        $report = AchievementReport::factory()->create(['project_id' => $project->id]);

        $this->get(route('projects.reports.pdf', [$project, $report]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_report_can_be_deleted(): void
    {
        $this->login();
        $project = Project::factory()->create();
        $report = AchievementReport::factory()->create(['project_id' => $project->id]);

        $this->delete(route('projects.reports.destroy', [$project, $report]))
            ->assertRedirect(route('projects.reports.index', $project));

        $this->assertDatabaseMissing('achievement_reports', ['id' => $report->id]);
    }

    public function test_report_from_another_project_returns_not_found(): void
    {
        $this->login();
        $project = Project::factory()->create();
        $report = AchievementReport::factory()->create(); // project lain

        $this->get(route('projects.reports.show', [$project, $report]))->assertNotFound();
        $this->get(route('projects.reports.pdf', [$project, $report]))->assertNotFound();
    }

    public function test_command_generates_reports_only_for_projects_with_activity_and_is_idempotent(): void
    {
        $project = Project::factory()->create(['title' => 'Project Aktif']);
        Task::factory()->create([
            'project_id' => $project->id,
            'title' => 'Task selesai bulan ini',
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);
        // Project tanpa aktivitas pada periode ini.
        Project::factory()->create(['title' => 'Project Sepi']);

        $args = ['--type' => 'month', '--date' => now()->toDateString()];

        $this->artisan('crm:generate-achievement-reports', $args)->assertSuccessful();
        $this->assertSame(1, AchievementReport::count());
        $this->assertStringContainsString('Task selesai bulan ini', AchievementReport::first()->summary);

        $this->artisan('crm:generate-achievement-reports', $args)->assertSuccessful();
        $this->assertSame(1, AchievementReport::count());
    }

    public function test_command_rejects_unknown_period_type(): void
    {
        $this->artisan('crm:generate-achievement-reports', ['--type' => 'harian'])->assertFailed();
        $this->assertSame(0, AchievementReport::count());
    }

    public function test_command_with_project_option_limits_scope(): void
    {
        $target = Project::factory()->create();
        $other = Project::factory()->create();
        foreach ([$target, $other] as $project) {
            Task::factory()->create([
                'project_id' => $project->id,
                'status' => TaskStatus::Done,
                'completed_at' => now(),
            ]);
        }

        $this->artisan('crm:generate-achievement-reports', [
            '--type' => 'week',
            '--date' => now()->toDateString(),
            '--project' => $target->id,
        ])->assertSuccessful();

        $this->assertSame(1, AchievementReport::count());
        $this->assertSame($target->id, AchievementReport::first()->project_id);
    }

    public function test_builder_and_model_helpers(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $this->assertSame(
            now()->startOfMonth()->toDateString(),
            ReportPeriod::Month->rangeFor(now())[0]->toDateString(),
        );
        $this->assertSame(
            Carbon::parse('2026-10-05')->toDateString(),
            ReportPeriod::Week->rangeFor(Carbon::parse('2026-10-07'))[0]->toDateString(),
        );

        $report = AchievementReport::factory()->create(['project_id' => $project->id]);
        $this->assertSame($project->id, $report->project->id);
    }
}
