<?php

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Projects\Http\Requests\ProjectJournalRequest;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectJournal;
use App\Http\Controllers\Controller;

/**
 * Jurnal progress project (F3-4). Penulis entri diambil dari user yang login
 * (tidak bisa dipalsukan lewat form).
 */
class ProjectJournalController extends Controller
{
    public function store(ProjectJournalRequest $request, Project $project)
    {
        $project->journals()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('projects.show', $project)
            ->with('success', 'Entri jurnal ditambahkan.');
    }

    public function update(ProjectJournalRequest $request, Project $project, ProjectJournal $journal)
    {
        $this->ensureBelongsToProject($project, $journal);

        $journal->update($request->validated());

        return redirect()->route('projects.show', $project)
            ->with('success', 'Entri jurnal diperbarui.');
    }

    public function destroy(Project $project, ProjectJournal $journal)
    {
        $this->ensureBelongsToProject($project, $journal);

        $journal->delete();

        return redirect()->route('projects.show', $project)
            ->with('success', 'Entri jurnal dihapus.');
    }

    /** Jurnal dari project lain -> 404 (route bersarang). */
    private function ensureBelongsToProject(Project $project, ProjectJournal $journal): void
    {
        abort_if($journal->project_id !== $project->id, 404);
    }
}
