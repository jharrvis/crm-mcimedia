<?php

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Http\Requests\ProjectRequest;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Project::query()
            ->with('client')
            ->withTaskCounts()
            ->when(request('q'), fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            ->when(request('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when(request('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('deadline')
            ->paginate(15)
            ->withQueryString();

        return view('projects.index', [
            'projects' => $projects,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'statuses' => ProjectStatus::cases(),
        ]);
    }

    public function create()
    {
        return view('projects.create', [
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'statuses' => ProjectStatus::cases(),
        ]);
    }

    public function store(ProjectRequest $request)
    {
        $project = Project::create($request->validated());

        return redirect()->route('projects.show', $project)
            ->with('success', 'Project berhasil ditambahkan.');
    }

    public function show(Project $project)
    {
        $project->load(['client', 'tasks', 'journals.author', 'achievementReports'])->loadTaskCounts();

        return view('projects.show', compact('project'));
    }

    public function edit(Project $project)
    {
        return view('projects.edit', [
            'project' => $project,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'statuses' => ProjectStatus::cases(),
        ]);
    }

    public function update(ProjectRequest $request, Project $project)
    {
        $project->update($request->validated());

        return redirect()->route('projects.show', $project)
            ->with('success', 'Project berhasil diperbarui.');
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return redirect()->route('projects.index')
            ->with('success', 'Project berhasil dihapus.');
    }
}
