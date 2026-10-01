<?php

namespace App\Domains\Tasks\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Enums\TaskPriority;
use App\Domains\Tasks\Enums\TaskStatus;
use App\Domains\Tasks\Http\Requests\TaskRequest;
use App\Domains\Tasks\Models\Task;
use App\Http\Controllers\Controller;
use App\Models\User;

class TaskController extends Controller
{
    public function index()
    {
        $tasks = Task::query()
            ->with(['client', 'project'])
            ->when(request('q'), fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            ->when(request('f_status', 'open'), function ($q, $status) {
                if ($status === 'open') {
                    $q->where('status', TaskStatus::Open);
                } elseif ($status === 'done') {
                    $q->where('status', TaskStatus::Done);
                }
            })
            ->when(request('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when(request('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when(request('project_id'), fn ($q, $id) => $q->where('project_id', $id))
            ->orderBy('due_date')
            ->paginate(15)
            ->withQueryString();

        return view('tasks.index', [
            'tasks' => $tasks,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('title')->get(['id', 'title']),
            'priorities' => TaskPriority::cases(),
        ]);
    }

    public function create()
    {
        return view('tasks.create', [
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('title')->get(['id', 'title']),
            'priorities' => TaskPriority::cases(),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(TaskRequest $request)
    {
        Task::create($request->validated());

        return redirect()->route('tasks.index')
            ->with('success', 'Tugas berhasil ditambahkan.');
    }

    public function edit(Task $task)
    {
        return view('tasks.edit', [
            'task' => $task,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('title')->get(['id', 'title']),
            'priorities' => TaskPriority::cases(),
            'statuses' => TaskStatus::cases(),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(TaskRequest $request, Task $task)
    {
        $task->update($request->validated());

        return redirect()->route('tasks.index')
            ->with('success', 'Tugas berhasil diperbarui.');
    }

    public function destroy(Task $task)
    {
        $task->delete();

        return redirect()->route('tasks.index')
            ->with('success', 'Tugas berhasil dihapus.');
    }

    public function complete(Task $task)
    {
        $task->complete();

        return back()->with('success', 'Tugas ditandai selesai.');
    }

    public function reopen(Task $task)
    {
        $task->reopen();

        return back()->with('success', 'Tugas dibuka kembali.');
    }
}
