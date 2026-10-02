<?php

namespace Tests\Feature\Projects;

use App\Domains\Projects\Enums\JournalCategory;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectJournalTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_guest_cannot_add_journal_entry(): void
    {
        $project = Project::factory()->create();

        $this->post(route('projects.journals.store', $project), [
            'occurred_on' => now()->toDateString(),
            'category' => 'progress',
            'body' => 'Tidak boleh masuk.',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('project_journals', 0);
    }

    public function test_can_add_journal_entry_with_current_user_as_author(): void
    {
        $user = $this->login();
        $project = Project::factory()->create();

        $response = $this->post(route('projects.journals.store', $project), [
            'occurred_on' => '2026-10-02',
            'category' => 'progress',
            'body' => 'Publish 3 artikel SEO minggu ini.',
        ]);

        $response->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseHas('project_journals', [
            'project_id' => $project->id,
            'user_id' => $user->id,
            'category' => 'progress',
            'body' => 'Publish 3 artikel SEO minggu ini.',
        ]);
    }

    public function test_journal_validation_rejects_missing_body_and_unknown_category(): void
    {
        $this->login();
        $project = Project::factory()->create();

        $this->post(route('projects.journals.store', $project), [
            'occurred_on' => now()->toDateString(),
            'category' => 'progress',
        ])->assertSessionHasErrors('body');

        $this->post(route('projects.journals.store', $project), [
            'occurred_on' => now()->toDateString(),
            'category' => 'entah',
            'body' => 'Isi entri.',
        ])->assertSessionHasErrors('category');

        $this->assertDatabaseCount('project_journals', 0);
    }

    public function test_timeline_is_ordered_newest_first_and_shows_author(): void
    {
        $user = $this->login();
        $user->update(['name' => 'Penulis Jurnal']);
        $project = Project::factory()->create();

        ProjectJournal::factory()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'occurred_on' => now()->subDays(3)->toDateString(),
            'body' => 'Entri paling lama.',
        ]);
        ProjectJournal::factory()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'occurred_on' => now()->subDay()->toDateString(),
            'body' => 'Entri kemarin.',
        ]);
        ProjectJournal::factory()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'occurred_on' => now()->toDateString(),
            'body' => 'Entri hari ini.',
        ]);

        $response = $this->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSeeInOrder(['Entri hari ini.', 'Entri kemarin.', 'Entri paling lama.']);
        $response->assertSee('Penulis Jurnal');
        $response->assertSee('Jurnal progress (3)');
    }

    public function test_journal_of_other_project_is_not_in_timeline(): void
    {
        $this->login();
        $project = Project::factory()->create();
        $other = Project::factory()->create();

        ProjectJournal::factory()->create(['project_id' => $other->id, 'body' => 'Entri project lain.']);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Entri project lain.');
    }

    public function test_can_update_journal_entry(): void
    {
        $this->login();
        $project = Project::factory()->create();
        $journal = ProjectJournal::factory()->create([
            'project_id' => $project->id,
            'category' => JournalCategory::Note,
        ]);

        $this->patch(route('projects.journals.update', [$project, $journal]), [
            'occurred_on' => now()->toDateString(),
            'category' => 'blocker',
            'body' => 'Menunggu akses GSC dari klien.',
        ])->assertRedirect(route('projects.show', $project));

        $journal->refresh();
        $this->assertSame('Menunggu akses GSC dari klien.', $journal->body);
        $this->assertSame(JournalCategory::Blocker, $journal->category);
    }

    public function test_can_delete_journal_entry(): void
    {
        $this->login();
        $project = Project::factory()->create();
        $journal = ProjectJournal::factory()->create(['project_id' => $project->id]);

        $this->delete(route('projects.journals.destroy', [$project, $journal]))
            ->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseMissing('project_journals', ['id' => $journal->id]);
    }

    public function test_journal_from_another_project_returns_not_found(): void
    {
        $this->login();
        $project = Project::factory()->create();
        $journal = ProjectJournal::factory()->create(); // project lain

        $this->patch(route('projects.journals.update', [$project, $journal]), [
            'occurred_on' => now()->toDateString(),
            'category' => 'progress',
            'body' => 'Percobaan lintas project.',
        ])->assertNotFound();

        $this->delete(route('projects.journals.destroy', [$project, $journal]))->assertNotFound();
        $this->assertDatabaseHas('project_journals', ['id' => $journal->id]);
    }
}
