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
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /** Nilai filter "belum selesai" di daftar tugas. */
    private const OPEN_STATUSES = [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Review];

    public function index()
    {
        $tasks = Task::query()
            ->with(['client', 'project'])
            ->when(request('q'), fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            ->when(request('f_status', 'open'), function ($q, $status) {
                if ($status === 'open') {
                    $q->whereIn('status', self::OPEN_STATUSES);
                } elseif ($status === 'done') {
                    $q->where('status', TaskStatus::Done);
                } elseif ($status !== 'all') {
                    // Filter per status kanban (todo/dikerjakan/review).
                    $q->where('status', $status);
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
            'statuses' => TaskStatus::cases(),
        ]);
    }

    /**
     * Papan kanban: kolom todo -> dikerjakan -> review -> selesai.
     * Filter: pencarian judul, klien, project, prioritas.
     */
    public function board()
    {
        $tasks = Task::query()
            ->with(['client', 'project', 'assignee'])
            ->when(request('q'), fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            ->when(request('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when(request('project_id'), fn ($q, $id) => $q->where('project_id', $id))
            ->when(request('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->orderBy('due_date')
            ->get();

        $columns = [];
        foreach (TaskStatus::boardColumns() as $status) {
            $columnTasks = $tasks->filter(fn (Task $task) => $task->status === $status)->values();
            $columns[] = [
                'status' => $status,
                'tasks' => $columnTasks,
                'count' => $columnTasks->count(),
            ];
        }

        return view('tasks.board', [
            'columns' => $columns,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('title')->get(['id', 'title']),
            'priorities' => TaskPriority::cases(),
            'total' => $tasks->count(),
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

    public function show(Task $task)
    {
        $task->load(['client', 'project', 'assignee']);

        return view('tasks.show', [
            'task' => $task,
        ]);
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

    /**
     * Ubah status task dari papan kanban (drag-drop, AJAX).
     * Mengembalikan JSON bila diminta AJAX; selain itu redirect biasa.
     */
    public function updateStatus(Request $request, Task $task)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(TaskStatus::class)],
        ]);

        $status = TaskStatus::from($validated['status']);

        $task->update([
            'status' => $status,
            // Selesai -> isi completed_at (sekali saja); kembali dari done -> kosongkan.
            'completed_at' => $status->isDone() ? ($task->completed_at ?? now()) : null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'task_id' => $task->id,
                'status' => $status->value,
                'label' => $status->label(),
                'completed' => $status->isDone(),
                'project_progress' => $task->project?->progressPercent(),
            ]);
        }

        return back()->with('success', "Status tugas \"{$task->title}\" diubah ke {$status->label()}.");
    }
}
