<?php

namespace Tests\Feature\Projects;

use App\Domains\Clients\Models\Client;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Enums\TaskStatus;
use App\Domains\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectProgressTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_progress_percent_is_computed_from_tasks(): void
    {
        $project = Project::factory()->create();

        foreach ([TaskStatus::Done, TaskStatus::Open, TaskStatus::Open, TaskStatus::Open] as $status) {
            Task::factory()->create(['project_id' => $project->id, 'status' => $status]);
        }

        $this->assertSame(25, $project->progressPercent());
        $this->assertSame(1, $project->doneTasksCount());
        $this->assertSame(4, $project->tasksCount());

        // Semua task selesai -> 100%.
        $project->tasks()->update(['status' => TaskStatus::Done]);
        $this->assertSame(100, $project->fresh()->progressPercent());
    }

    public function test_project_without_tasks_is_zero_percent_unless_done(): void
    {
        $running = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        $this->assertSame(0, $running->progressPercent());

        $done = Project::factory()->create(['status' => ProjectStatus::Done]);
        $this->assertSame(100, $done->progressPercent());
    }

    public function test_project_list_shows_progress_column_and_counts(): void
    {
        $this->login();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id, 'title' => 'Project Progress']);
        Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Done]);
        Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Open]);

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Progress')
            ->assertSee('Project Progress')
            ->assertSee('50%')
            ->assertSee('(1/2)');
    }

    public function test_project_detail_shows_progress(): void
    {
        $this->login();
        $project = Project::factory()->create();
        Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Done]);
        Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Open]);
        Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Open]);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Progress')
            ->assertSee('33%');
    }

    public function test_overdue_project_is_marked_in_list_and_detail(): void
    {
        $this->login();
        $overdue = Project::factory()->create([
            'title' => 'Project Lewat Deadline',
            'status' => ProjectStatus::InProgress,
            'deadline' => now()->subDays(3)->toDateString(),
        ]);
        $onTrack = Project::factory()->create([
            'title' => 'Project Aman',
            'status' => ProjectStatus::InProgress,
            'deadline' => now()->addDays(5)->toDateString(),
        ]);
        // Selesai walau deadlinenya lewat: bukan overdue.
        Project::factory()->create([
            'title' => 'Project Sudah Selesai',
            'status' => ProjectStatus::Done,
            'deadline' => now()->subDays(3)->toDateString(),
        ]);

        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse($onTrack->isOverdue());

        $response = $this->get(route('projects.index'));
        $response->assertOk()->assertSee('Overdue');

        $this->get(route('projects.show', $overdue))
            ->assertOk()
            ->assertSee('Overdue');
    }
}
