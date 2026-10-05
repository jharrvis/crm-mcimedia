@extends('layouts.app')

@section('title', 'Papan Tugas')

@section('content')
@php
    $priorityOptions = ['' => 'Semua prioritas'] + collect($priorities)->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
    $clientOptions = ['' => 'Semua klien'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all();
    $projectOptions = ['' => 'Semua project'] + collect($projects)->mapWithKeys(fn ($p) => [$p->id => $p->title])->all();
@endphp

<x-page-header title="Papan Tugas" icon="layout-grid"
               subtitle="{{ $total }} tugas — geser kartu antar kolom untuk mengubah status.">
    <x-btn :href="route('tasks.index', request()->query())" variant="outline">Daftar</x-btn>
    <x-btn :href="route('tasks.create')" icon="plus">Tambah tugas</x-btn>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('tasks.board') }}" class="mb-4 grid gap-2 sm:grid-cols-3 lg:grid-cols-5">
        <input name="q" value="{{ request('q') }}" placeholder="Cari judul…"
               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:ring-brand-900">
        <x-input name="priority" type="select" :options="$priorityOptions" :value="request('priority')" placeholder="" />
        <x-input name="client_id" type="select" :options="$clientOptions" :value="(string) request('client_id')" placeholder="" />
        <x-input name="project_id" type="select" :options="$projectOptions" :value="(string) request('project_id')" placeholder="" />
        <div class="flex items-center gap-2">
            <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
            @if (request()->hasAny(['q', 'priority', 'client_id', 'project_id']))
                <x-btn :href="route('tasks.board')" variant="ghost">Reset</x-btn>
            @endif
        </div>
    </form>
</x-card>

<div id="board" class="flex gap-4 overflow-x-auto pb-4">
    @foreach ($columns as $column)
        <x-card class="flex w-72 shrink-0 flex-col" :padded="false">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full {{ ['open' => 'bg-slate-400', 'in_progress' => 'bg-amber-500', 'review' => 'bg-brand-500', 'done' => 'bg-green-500'][$column['status']->value] }}"></span>
                    <h2 class="text-sm font-bold">{{ $column['status']->label() }}</h2>
                </div>
                <span data-count class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $column['count'] }}</span>
            </div>

            <div data-dropzone class="min-h-[8rem] flex-1 space-y-2 p-3">
                @forelse ($column['tasks'] as $task)
                    @php
                        $statusVariant = ['open' => 'slate', 'in_progress' => 'warning', 'review' => 'info', 'done' => 'success'][$task->status->value];
                        $prioVariant = ['high' => 'danger', 'medium' => 'warning', 'low' => 'slate'][$task->priority->value];
                    @endphp
                    <article draggable="true" data-task-id="{{ $task->id }}"
                             class="cursor-grab rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm shadow-sm active:cursor-grabbing dark:border-slate-700 dark:bg-slate-800">
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('tasks.show', $task) }}" class="font-semibold hover:text-brand-600">{{ $task->title }}</a>
                            <x-badge :variant="$prioVariant">{{ $task->priority->label() }}</x-badge>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $task->client?->name ?? '—' }}
                            @if ($task->project) · <a href="{{ route('projects.show', $task->project) }}" class="hover:text-brand-600">{{ $task->project->title }}</a> @endif
                        </p>
                        <p class="mt-1 text-xs {{ $task->isOverdue() ? 'font-semibold text-red-600' : 'text-slate-500' }}">
                            Due: {{ tgl_id($task->due_date) }}
                            @if ($task->isOverdue()) · lewat deadline @endif
                        </p>
                    </article>
                @empty
                    <p class="px-1 py-4 text-center text-xs text-slate-400">Belum ada tugas.</p>
                @endforelse
            </div>
        </x-card>
    @endforeach
</div>

<script>
(function () {
    const board = document.getElementById('board');
    if (!board) return;

    const statusUrl = @json(route('tasks.status', ['task' => '__ID__']));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let dragged = null;

    const refreshCounts = () => {
        board.querySelectorAll('[data-column]').forEach((section) => {
            const count = section.querySelectorAll('[data-task-id]').length;
            const badge = section.querySelector('[data-count]');
            if (badge) badge.textContent = count;
        });
    };

    board.querySelectorAll('[data-task-id]').forEach((card) => {
        card.addEventListener('dragstart', (event) => {
            dragged = card;
            event.dataTransfer.setData('text/plain', card.dataset.taskId);
            event.dataTransfer.effectAllowed = 'move';
        });
        card.addEventListener('dragend', () => { dragged = null; });
    });

    board.querySelectorAll('[data-column]').forEach((section) => {
        section.addEventListener('dragover', (event) => {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        });
        section.addEventListener('drop', async (event) => {
            event.preventDefault();
            if (!dragged) return;

            const targetZone = section.querySelector('[data-dropzone]');
            const origin = dragged.parentElement;
            const status = section.dataset.column;
            if (origin === targetZone) return;

            const placeholder = targetZone.querySelector('p.text-slate-400');
            if (placeholder) placeholder.remove();

            targetZone.appendChild(dragged);
            refreshCounts();

            try {
                const response = await fetch(statusUrl.replace('__ID__', dragged.dataset.taskId), {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ status: status }),
                });

                if (!response.ok) throw new Error('HTTP ' + response.status);
                refreshCounts();
            } catch (error) {
                // Gagal simpan -> kembalikan kartu ke kolom asal.
                origin.appendChild(dragged);
                refreshCounts();
                alert('Gagal memindahkan tugas. Perubahan dibatalkan.');
            }
        });
    });
})();
</script>
@endsection
