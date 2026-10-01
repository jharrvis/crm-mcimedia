@extends('layouts.app')

@section('title', 'Detail Project')

@section('content')
<div class="mb-4 flex items-center justify-between">
    <a href="{{ route('projects.index') }}" class="text-sm text-indigo-600 hover:underline">← Kembali ke daftar</a>
    <div class="flex gap-2">
        <a href="{{ route('projects.edit', $project) }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Ubah</a>
        <form method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm('Hapus project ini?')">
            @csrf @method('DELETE')
            <button class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Hapus</button>
        </form>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="mb-3 text-lg font-bold">{{ $project->title }}</h2>
        <dl class="space-y-2 text-sm">
            <div><dt class="text-slate-500">Klien</dt><dd><a href="{{ route('clients.show', $project->client) }}" class="text-indigo-600 hover:underline">{{ $project->client?->name ?? '—' }}</a></dd></div>
            <div><dt class="text-slate-500">Status</dt><dd><span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">{{ $project->status->label() }}</span></dd></div>
            <div><dt class="text-slate-500">Deadline</dt><dd>{{ tgl_id($project->deadline) }}</dd></div>
            <div><dt class="text-slate-500">Nilai project</dt><dd class="font-semibold">{{ rupiah($project->value) }}</dd></div>
        </dl>
        @if ($project->description)
            <div class="mt-4 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                <p class="mb-1 text-slate-500">Deskripsi</p>
                <p class="whitespace-pre-line">{{ $project->description }}</p>
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 lg:col-span-2">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="font-bold">Tugas project ini ({{ $project->tasks->count() }})</h2>
            <a href="{{ route('tasks.create') }}" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">Tambah tugas</a>
        </div>
        @if ($project->tasks->isEmpty())
            <p class="text-sm text-slate-500">Belum ada tugas untuk project ini.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase text-slate-500">
                        <th class="py-2 pr-4">Judul</th><th class="py-2 pr-4">Prioritas</th><th class="py-2 pr-4">Due date</th><th class="py-2 pr-4">Status</th><th class="py-2">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($project->tasks->sortBy('due_date') as $task)
                            @php
                                $prioColor = ['high' => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300', 'medium' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'low' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'][$task->priority->value];
                            @endphp
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="py-2 pr-4"><a href="{{ route('tasks.edit', $task) }}" class="font-medium hover:text-indigo-600">{{ $task->title }}</a></td>
                                <td class="py-2 pr-4"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $prioColor }}">{{ $task->priority->label() }}</span></td>
                                <td class="py-2 pr-4 {{ $task->due_date && $task->due_date->isPast() && $task->status->value === 'open' ? 'font-semibold text-red-600' : '' }}">{{ tgl_id($task->due_date) }}</td>
                                <td class="py-2 pr-4"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $task->status->value === 'done' ? 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' }}">{{ $task->status->label() }}</span></td>
                                <td class="whitespace-nowrap py-2 text-sm">
                                    @if ($task->status->value === 'open')
                                        <form method="POST" action="{{ route('tasks.complete', $task) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button class="text-green-600 hover:underline">Selesai</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('tasks.reopen', $task) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button class="text-amber-600 hover:underline">Buka kembali</button>
                                        </form>
                                    @endif
                                    <span class="text-slate-300"> · </span>
                                    <a href="{{ route('tasks.edit', $task) }}" class="text-indigo-600 hover:underline">Ubah</a>
                                    <span class="text-slate-300"> · </span>
                                    <form method="POST" action="{{ route('tasks.destroy', $task) }}" class="inline" onsubmit="return confirm('Hapus tugas ini?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:underline">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
