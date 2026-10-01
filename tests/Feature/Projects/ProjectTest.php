<?php

namespace Tests\Feature\Projects;

use App\Domains\Clients\Models\Client;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
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
        $this->get(route('projects.index'))->assertRedirect(route('login'));
    }

    public function test_can_create_project(): void
    {
        $this->login();
        $client = Client::factory()->create();

        $response = $this->post(route('projects.store'), [
            'client_id' => $client->id,
            'title' => 'Website baru',
            'status' => 'in_progress',
            'value' => 5000000,
        ]);

        $project = Project::first();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertDatabaseHas('projects', ['title' => 'Website baru', 'value' => 5000000]);
    }

    public function test_title_is_required(): void
    {
        $this->login();
        $client = Client::factory()->create();

        $this->post(route('projects.store'), [
            'client_id' => $client->id,
            'title' => '',
            'status' => 'new',
        ])->assertSessionHasErrors('title');
    }

    public function test_can_update_project(): void
    {
        $this->login();
        $project = Project::factory()->create(['title' => 'Lama']);

        $this->put(route('projects.update', $project), [
            'client_id' => $project->client_id,
            'title' => 'Baru',
            'status' => 'done',
        ])->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'title' => 'Baru', 'status' => 'done']);
    }

    public function test_can_delete_project(): void
    {
        $this->login();
        $project = Project::factory()->create();

        $this->delete(route('projects.destroy', $project))
            ->assertRedirect(route('projects.index'));

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_show_lists_project_tasks(): void
    {
        $this->login();
        $project = Project::factory()->create();
        Task::factory()->create(['project_id' => $project->id, 'title' => 'Tugas khusus project']);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Tugas khusus project');
    }
}
