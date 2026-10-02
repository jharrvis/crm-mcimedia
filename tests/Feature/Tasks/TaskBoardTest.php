<?php

namespace Tests\Feature\Tasks;

use App\Domains\Clients\Models\Client;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Enums\TaskStatus;
use App\Domains\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskBoardTest extends TestCase
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
        $this->get(route('tasks.board'))->assertRedirect(route('login'));
    }

    public function test_board_renders_four_columns_in_order_with_tasks(): void
    {
        $this->login();

        Task::factory()->create(['title' => 'Kartu Todo', 'status' => TaskStatus::Open]);
        Task::factory()->create(['title' => 'Kartu Kerjakan', 'status' => TaskStatus::InProgress]);
        Task::factory()->create(['title' => 'Kartu Tinjau', 'status' => TaskStatus::Review]);
        Task::factory()->create(['title' => 'Kartu Selesai', 'status' => TaskStatus::Done]);

        $response = $this->get(route('tasks.board'));

        $response->assertOk();
        $response->assertSeeInOrder(['Todo', 'Dikerjakan', 'Review', 'Selesai']);
        $response->assertSee('Kartu Todo');
        $response->assertSee('Kartu Kerjakan');
        $response->assertSee('Kartu Tinjau');
        $response->assertSee('Kartu Selesai');
        // Markup drag-drop untuk AJAX.
        $response->assertSee('draggable="true"', false);
        $response->assertSee('data-dropzone', false);
    }

    public function test_board_can_filter_by_project_and_client(): void
    {
        $this->login();

        $clientA = Client::factory()->create(['name' => 'Klien Alfa']);
        $clientB = Client::factory()->create(['name' => 'Klien Bravo']);
        $projectA = Project::factory()->create(['client_id' => $clientA->id, 'title' => 'Project Alfa']);
        $projectB = Project::factory()->create(['client_id' => $clientB->id, 'title' => 'Project Bravo']);

        Task::factory()->create(['title' => 'Tugas Alfa', 'project_id' => $projectA->id, 'client_id' => $clientA->id]);
        Task::factory()->create(['title' => 'Tugas Bravo', 'project_id' => $projectB->id, 'client_id' => $clientB->id]);

        $this->get(route('tasks.board', ['project_id' => $projectA->id]))
            ->assertOk()
            ->assertSee('Tugas Alfa')
            ->assertDontSee('Tugas Bravo');

        $this->get(route('tasks.board', ['client_id' => $clientB->id]))
            ->assertOk()
            ->assertSee('Tugas Bravo')
            ->assertDontSee('Tugas Alfa');
    }

    public function test_drag_drop_status_update_returns_json_and_persists(): void
    {
        $this->login();
        $task = Task::factory()->create(['status' => TaskStatus::Open]);

        $response = $this->patchJson(route('tasks.status', $task), ['status' => 'in_progress']);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'task_id' => $task->id,
                'status' => 'in_progress',
                'label' => 'Dikerjakan',
                'completed' => false,
            ]);

        $task->refresh();
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertNull($task->completed_at);
    }

    public function test_moving_to_done_fills_completed_at_and_back_clears_it(): void
    {
        $this->login();
        $task = Task::factory()->create(['status' => TaskStatus::Review]);

        $this->patchJson(route('tasks.status', $task), ['status' => 'done'])->assertOk();
        $task->refresh();
        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertNotNull($task->completed_at);

        $this->patchJson(route('tasks.status', $task), ['status' => 'open'])->assertOk();
        $task->refresh();
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertNull($task->completed_at);
    }

    public function test_status_update_returns_project_progress(): void
    {
        $this->login();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Open]);
        Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Open]);

        $this->patchJson(route('tasks.status', $task), ['status' => 'done'])
            ->assertOk()
            ->assertJson(['project_progress' => 50]);
    }

    public function test_status_update_rejects_unknown_status(): void
    {
        $this->login();
        $task = Task::factory()->create();

        $this->patchJson(route('tasks.status', $task), ['status' => 'selesai-banget'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(TaskStatus::Open, $task->fresh()->status);
    }

    public function test_status_update_without_json_redirects_back(): void
    {
        $this->login();
        $task = Task::factory()->create(['status' => TaskStatus::Open]);

        $this->from(route('tasks.board'))
            ->patch(route('tasks.status', $task), ['status' => 'review'])
            ->assertRedirect(route('tasks.board'));

        $this->assertSame(TaskStatus::Review, $task->fresh()->status);
    }

    public function test_list_view_remains_available_and_shows_new_statuses(): void
    {
        $this->login();
        Task::factory()->create(['title' => 'Tugas Dikerjakan', 'status' => TaskStatus::InProgress]);

        $this->get(route('tasks.index', ['f_status' => 'all']))
            ->assertOk()
            ->assertSee('Tugas Dikerjakan')
            ->assertSee('Dikerjakan');

        $this->get(route('tasks.index', ['f_status' => 'in_progress']))
            ->assertOk()
            ->assertSee('Tugas Dikerjakan');
    }

    public function test_open_filter_includes_all_unfinished_statuses(): void
    {
        $this->login();
        Task::factory()->create(['title' => 'Tugas Todo', 'status' => TaskStatus::Open]);
        Task::factory()->create(['title' => 'Tugas Review', 'status' => TaskStatus::Review]);
        Task::factory()->create(['title' => 'Tugas Selesai', 'status' => TaskStatus::Done]);

        $this->get(route('tasks.index', ['f_status' => 'open']))
            ->assertOk()
            ->assertSee('Tugas Todo')
            ->assertSee('Tugas Review')
            ->assertDontSee('Tugas Selesai');
    }
}
