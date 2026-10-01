<?php

namespace Tests\Feature\Tasks;

use App\Domains\Clients\Models\Client;
use App\Domains\Tasks\Enums\TaskStatus;
use App\Domains\Tasks\Models\Task;
use App\Models\User;
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
}
