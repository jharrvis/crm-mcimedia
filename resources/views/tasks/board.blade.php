@extends('layouts.app')

@section('title', 'Papan Tugas')

@section('content')
<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <form method="GET" action="{{ route('tasks.board') }}" class="flex flex-wrap gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Cari judul…"
               class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
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
        @if (request()->hasAny(['q', 'priority', 'client_id', 'project_id']))
            <a href="{{ route('tasks.board') }}" class="rounded-lg px-3 py-2 text-sm text-slate-500 hover:underline">Reset</a>
        @endif
    </form>
    <div class="flex shrink-0 items-center gap-2">
        <div class="rounded-lg border border-slate-300 p-0.5 text-sm dark:border-slate-700">
            <span class="rounded-md bg-brand-600 px-3 py-1.5 font-semibold text-white">Papan</span>
            <a href="{{ route('tasks.index', request()->query()) }}" class="px-3 py-1.5 text-slate-600 hover:text-brand-600 dark:text-slate-300">Daftar</a>
        </div>
        <a href="{{ route('tasks.create') }}"
           class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Tambah tugas</a>
    </div>
</div>

<p class="mb-3 text-xs text-slate-500">
    {{ $total }} tugas ditampilkan · geser kartu antar kolom untuk mengubah status (tersimpan otomatis).
</p>

<div id="board" class="flex gap-4 overflow-x-auto pb-4">
    @foreach ($columns as $column)
        <section class="flex w-72 shrink-0 flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
                 data-column="{{ $column['status']->value }}">
            <header class="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full {{ ['open' => 'bg-slate-400', 'in_progress' => 'bg-amber-500', 'review' => 'bg-brand-500', 'done' => 'bg-green-500'][$column['status']->value] }}"></span>
                    <h2 class="text-sm font-bold">{{ $column['status']->label() }}</h2>
                </div>
                <span data-count class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $column['count'] }}</span>
            </header>

            <div data-dropzone class="min-h-[8rem] flex-1 space-y-2 p-3">
                @forelse ($column['tasks'] as $task)
                    @php
                        $prioColor = ['high' => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300', 'medium' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'low' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'][$task->priority->value];
                    @endphp
                    <article draggable="true" data-task-id="{{ $task->id }}"
                             class="cursor-grab rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm shadow-sm active:cursor-grabbing dark:border-slate-700 dark:bg-slate-800">
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('tasks.show', $task) }}" class="font-medium hover:text-brand-600">{{ $task->title }}</a>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $prioColor }}">{{ $task->priority->label() }}</span>
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
        </section>
    @endforeach
</div>

<script>
(function () {
    const board = document.getElementById('board');
    if (!board) return;

    // URL template dari route Laravel: /tasks/__ID__/status
    const statusUrl = @json(route('tasks.status', ['task' => '__ID__']));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let dragged = null;

    const refreshCounts = () => {
        board.querySelectorAll('section[data-column]').forEach((section) => {
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

    board.querySelectorAll('section[data-column]').forEach((section) => {
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
