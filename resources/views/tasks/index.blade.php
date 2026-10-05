@extends('layouts.app')

@section('title', 'Tugas')

@section('content')
<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <form method="GET" action="{{ route('tasks.index') }}" class="flex flex-wrap gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Cari judul…"
               class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <select name="f_status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            @foreach (['open' => 'Belum selesai', 'in_progress' => 'Dikerjakan', 'review' => 'Review', 'done' => 'Selesai', 'all' => 'Semua'] as $val => $label)
                <option value="{{ $val }}" @selected(request('f_status', 'open') === $val)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="priority" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="">Semua prioritas</option>
            @foreach ($priorities as $priority)
                <option value="{{ $priority->value }}" @selected(request('priority') === $priority->value)>{{ $priority->label() }}</option>
            @endforeach
        </select>
        <select name="client_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="">Semua klien</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected(request('client_id') == $client->id)>{{ $client->name }}</option>
            @endforeach
        </select>
        <select name="project_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="">Semua project</option>
            @foreach ($projects as $project)
                <option value="{{ $project->id }}" @selected(request('project_id') == $project->id)>{{ $project->title }}</option>
            @endforeach
        </select>
        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Filter</button>
        @if (request()->hasAny(['q', 'f_status', 'priority', 'client_id', 'project_id']))
            <a href="{{ route('tasks.index') }}" class="rounded-lg px-3 py-2 text-sm text-slate-500 hover:underline">Reset</a>
        @endif
    </form>
    <div class="flex shrink-0 items-center gap-2">
        <div class="rounded-lg border border-slate-300 p-0.5 text-sm dark:border-slate-700">
            <a href="{{ route('tasks.board', request()->query()) }}" class="px-3 py-1.5 text-slate-600 hover:text-brand-600 dark:text-slate-300">Papan</a>
            <span class="rounded-md bg-brand-600 px-3 py-1.5 font-semibold text-white">Daftar</span>
        </div>
        <a href="{{ route('tasks.create') }}"
           class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Tambah tugas</a>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs uppercase text-slate-500">
                <th class="py-2 pr-4">Judul</th><th class="py-2 pr-4">Klien</th><th class="py-2 pr-4">Project</th>
                <th class="py-2 pr-4">Prioritas</th><th class="py-2 pr-4">Due date</th><th class="py-2 pr-4">Status</th><th class="py-2">Aksi</th>
            </tr></thead>
            <tbody>
                @forelse ($tasks as $task)
                    @php
                        $prioColor = ['high' => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300', 'medium' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'low' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'][$task->priority->value];
                    @endphp
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="py-2 pr-4"><a href="{{ route('tasks.show', $task) }}" class="font-medium hover:text-brand-600">{{ $task->title }}</a></td>
                        <td class="py-2 pr-4">{{ $task->client?->name ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $task->project?->title ?? '—' }}</td>
                        <td class="py-2 pr-4"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $prioColor }}">{{ $task->priority->label() }}</span></td>
                        <td class="py-2 pr-4 {{ $task->isOverdue() ? 'font-semibold text-red-600' : '' }}">{{ tgl_id($task->due_date) }}</td>
                        <td class="py-2 pr-4"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $task->status->badgeClasses() }}">{{ $task->status->label() }}</span></td>
                        <td class="whitespace-nowrap py-2 text-sm">
                            @if ($task->status->isDone())
                                <form method="POST" action="{{ route('tasks.reopen', $task) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <button class="text-amber-600 hover:underline">Buka kembali</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('tasks.complete', $task) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <button class="text-green-600 hover:underline">Selesai</button>
                                </form>
                            @endif
                            <span class="text-slate-300"> · </span>
                            <a href="{{ route('tasks.edit', $task) }}" class="text-brand-600 hover:underline">Ubah</a>
                            <span class="text-slate-300"> · </span>
                            <form method="POST" action="{{ route('tasks.destroy', $task) }}" class="inline" onsubmit="return confirm('Hapus tugas ini?')">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:underline">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-center text-slate-500">Belum ada tugas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $tasks->links() }}</div>
</div>
@endsection
