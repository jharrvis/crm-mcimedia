@extends('layouts.app')

@section('title', 'Tugas')

@section('content')
@php
    $statusOptions = ['open' => 'Belum selesai', 'in_progress' => 'Dikerjakan', 'review' => 'Review', 'done' => 'Selesai', 'all' => 'Semua'];
    $priorityOptions = ['' => 'Semua prioritas'] + collect($priorities)->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
    $clientOptions = ['' => 'Semua klien'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all();
    $projectOptions = ['' => 'Semua project'] + collect($projects)->mapWithKeys(fn ($p) => [$p->id => $p->title])->all();
@endphp

<x-page-header title="Tugas" subtitle="Semua tugas lintas klien dan project." icon="list-checks">
    <div class="flex items-center gap-2">
        <x-btn :href="route('tasks.board', request()->query())" variant="outline" icon="layout-grid">Papan</x-btn>
        <x-btn :href="route('tasks.create')" icon="plus">Tambah tugas</x-btn>
    </div>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('tasks.index') }}" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
        <input name="q" value="{{ request('q') }}" placeholder="Cari judul…"
               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:ring-brand-900">
        <x-input name="f_status" type="select" :options="$statusOptions" :value="request('f_status', 'open')" />
        <x-input name="priority" type="select" :options="$priorityOptions" :value="request('priority')" />
        <x-input name="client_id" type="select" :options="$clientOptions" :value="(string) request('client_id')" />
        <x-input name="project_id" type="select" :options="$projectOptions" :value="(string) request('project_id')" />
        <div class="flex items-center gap-2 sm:col-span-2 lg:col-span-5">
            <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
            @if (request()->hasAny(['q', 'f_status', 'priority', 'client_id', 'project_id']))
                <x-btn :href="route('tasks.index')" variant="ghost">Reset</x-btn>
            @endif
        </div>
    </form>

    <x-table>
        <thead><tr>
            <th>Judul</th>
            <th>Klien</th>
            <th>Project</th>
            <th>Prioritas</th>
            <th>Due date</th>
            <th>Status</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($tasks as $task)
                @php
                    $prioVariant = ['high' => 'danger', 'medium' => 'warning', 'low' => 'slate'][$task->priority->value];
                    $statusVariant = ['open' => 'slate', 'in_progress' => 'warning', 'review' => 'info', 'done' => 'success'][$task->status->value];
                @endphp
                <tr>
                    <td><a href="{{ route('tasks.show', $task) }}" class="font-semibold hover:text-brand-600">{{ $task->title }}</a></td>
                    <td>{{ $task->client?->name ?? '—' }}</td>
                    <td>{{ $task->project?->title ?? '—' }}</td>
                    <td><x-badge :variant="$prioVariant">{{ $task->priority->label() }}</x-badge></td>
                    <td class="{{ $task->isOverdue() ? 'font-semibold text-red-600' : '' }}">{{ tgl_id($task->due_date) }}</td>
                    <td><x-badge :variant="$statusVariant" :dot="true">{{ $task->status->label() }}</x-badge></td>
                    <td class="whitespace-nowrap text-right">
                        @if ($task->status->isDone())
                            <form method="POST" action="{{ route('tasks.reopen', $task) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-sm font-semibold text-amber-600 hover:underline">Buka kembali</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('tasks.complete', $task) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-sm font-semibold text-green-600 hover:underline">Selesai</button>
                            </form>
                        @endif
                        <span class="mx-1 text-slate-300">|</span>
                        <a href="{{ route('tasks.edit', $task) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('tasks.destroy', $task) }}" class="inline" onsubmit="return confirm('Hapus tugas ini?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <x-empty-state title="Belum ada tugas" icon="list-checks"
                                   description="Buat tugas pertama, atau ubah filter di atas." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($tasks->hasPages())
        <div class="mt-4">{{ $tasks->links() }}</div>
    @endif
</x-card>
@endsection
