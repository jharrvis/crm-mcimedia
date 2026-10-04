<?php

namespace Tests\Feature\Tasks;

use App\Domains\Clients\Models\Client;
use App\Domains\Tasks\Enums\TaskStatus;
use App\Domains\Tasks\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
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
        $this->get(route('tasks.index'))->assertRedirect(route('login'));
    }

    public function test_can_create_task(): void
    {
        $this->login();
        $client = Client::factory()->create();

        $response = $this->post(route('tasks.store'), [
            'title' => 'Follow up klien',
            'client_id' => $client->id,
            'priority' => 'high',
            'due_date' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('tasks', [
            'title' => 'Follow up klien',
            'priority' => 'high',
            'status' => 'open',
        ]);
    }

    public function test_complete_marks_task_done(): void
    {
        $this->login();
        $task = Task::factory()->create(['status' => TaskStatus::Open]);

        $this->patch(route('tasks.complete', $task))->assertRedirect();

        $task->refresh();
        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_reopen_marks_task_open(): void
    {
        $this->login();
        $task = Task::factory()->create();
        $task->complete();

        $this->patch(route('tasks.reopen', $task))->assertRedirect();

        $task->refresh();
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertNull($task->completed_at);
    }

    public function test_urgent_scope_returns_task_due_tomorrow(): void
    {
        $this->login();
        $urgent = Task::factory()->create(['due_date' => now()->addDay()->toDateString()]);
        Task::factory()->create(['due_date' => now()->addDays(30)->toDateString()]);

        $this->assertTrue(Task::urgent()->whereKey($urgent->id)->exists());
        $this->assertSame(1, Task::urgent()->count());
    }

    public function test_can_delete_task(): void
    {
        $this->login();
        $task = Task::factory()->create();

        $this->delete(route('tasks.destroy', $task))
            ->assertRedirect(route('tasks.index'));

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_show_displays_task_details(): void
    {
        $this->login();
        $task = Task::factory()->create(['title' => 'Test Task Detail']);

        $response = $this->get(route('tasks.show', $task));

        $response->assertOk();
        $response->assertSee('Test Task Detail');
        $response->assertSee('Detail Tugas');
    }

    public function test_show_displays_kanban_not_connected_when_no_card_id(): void
    {
        $this->login();
        $task = Task::factory()->create([
            'title' => 'Task without kanban',
            'kanban_card_id' => null,
        ]);

        $response = $this->get(route('tasks.show', $task));

        $response->assertOk();
        $response->assertSee('Aktivitas Tim (Kanban)');
        $response->assertSee('Belum terhubung ke kanban');
    }

    public function test_show_displays_kanban_section_with_data(): void
    {
        $this->login();
        $task = Task::factory()->create([
            'title' => 'Task with kanban',
            'kanban_card_id' => 't_abc123',
            'kanban_status' => 'running',
            'kanban_summary' => 'Summary from agent',
            'kanban_comments' => [
                ['at' => Carbon::now()->toISOString(), 'author' => 'Agent One', 'body' => 'First comment'],
                ['at' => Carbon::now()->subHour()->toISOString(), 'author' => 'Agent Two', 'body' => 'Second comment'],
            ],
            'kanban_synced_at' => Carbon::now(),
        ]);

        $response = $this->get(route('tasks.show', $task));

        $response->assertOk();
        $response->assertSee('Aktivitas Tim (Kanban)');
        $response->assertSee('t_abc123');
        $response->assertSee('Running');
        $response->assertSee('Summary from agent');
        $response->assertSee('Agent One');
        $response->assertSee('First comment');
        $response->assertSee('Agent Two');
        $response->assertSee('Second comment');
        $response->assertSee('Disinkronkan');
    }

    public function test_kanban_comments_cast_to_array(): void
    {
        $this->login();
        $comments = [
            ['at' => '2026-10-01T10:00:00Z', 'author' => 'Test Author', 'body' => 'Test comment'],
        ];

        $task = Task::factory()->create([
            'kanban_comments' => $comments,
        ]);

        $task->refresh();

        $this->assertIsArray($task->kanban_comments);
        $this->assertCount(1, $task->kanban_comments);
        $this->assertSame('Test Author', $task->kanban_comments[0]['author']);
        $this->assertSame('Test comment', $task->kanban_comments[0]['body']);
    }

    public function test_kanban_comments_empty_when_null(): void
    {
        $task = Task::factory()->create([
            'kanban_comments' => null,
        ]);

        $this->assertNull($task->kanban_comments);
    }

    public function test_index_links_to_show_page(): void
    {
        $this->login();
        $task = Task::factory()->create(['title' => 'Index Link Test']);

        $response = $this->get(route('tasks.index'));

        $response->assertOk();
        $response->assertSee(route('tasks.show', $task));
    }

    public function test_board_links_to_show_page(): void
    {
        $this->login();
        $task = Task::factory()->create(['title' => 'Board Link Test']);

        $response = $this->get(route('tasks.board'));

        $response->assertOk();
        $response->assertSee(route('tasks.show', $task));
    }

    public function test_kanban_status_badge_colors(): void
    {
        $this->login();

        $statuses = [
            'ready' => 'bg-slate-100',
            'running' => 'bg-blue-100',
            'blocked' => 'bg-red-100',
            'done' => 'bg-green-100',
        ];

        foreach ($statuses as $status => $expectedClass) {
            $task = Task::factory()->create([
                'kanban_card_id' => 't_test',
                'kanban_status' => $status,
            ]);

            $response = $this->get(route('tasks.show', $task));
            $response->assertOk();
            $response->assertSee($expectedClass);
        }
    }
}
