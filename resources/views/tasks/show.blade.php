@extends('layouts.app')

@section('title', $task->title)

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <a href="{{ route('tasks.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Kembali ke daftar</a>
    <div class="flex items-center gap-2">
        <a href="{{ route('tasks.edit', $task) }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Ubah</a>
        <form method="POST" action="{{ route('tasks.destroy', $task) }}"
              onsubmit="return confirm('Hapus tugas ini?')">
            @csrf
            @method('DELETE')
            <button class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Hapus</button>
        </form>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-3 font-bold">Detail Tugas</h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs uppercase text-slate-500">Judul</dt>
                    <dd class="font-medium">{{ $task->title }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Deskripsi</dt>
                    <dd class="whitespace-pre-wrap">{{ $task->description ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Klien</dt>
                    <dd>
                        @if ($task->client)
                            <a href="{{ route('clients.show', $task->client) }}" class="text-indigo-600 hover:underline">{{ $task->client->name }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Project</dt>
                    <dd>
                        @if ($task->project)
                            <a href="{{ route('projects.show', $task->project) }}" class="text-indigo-600 hover:underline">{{ $task->project->title }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Penanggung jawab</dt>
                    <dd>{{ $task->assignee?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Prioritas</dt>
                    <dd>
                        @php
                            $prioColor = ['high' => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300', 'medium' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'low' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'][$task->priority->value];
                        @endphp
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $prioColor }}">{{ $task->priority->label() }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Status</dt>
                    <dd>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $task->status->badgeClasses() }}">{{ $task->status->label() }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Deadline</dt>
                    <dd class="{{ $task->isOverdue() ? 'font-semibold text-red-600' : '' }}">
                        {{ tgl_id($task->due_date) }}
                        @if ($task->isOverdue()) · lewat deadline @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Dibuat</dt>
                    <dd>{{ $task->created_at->format('d/m/Y H:i') }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Diperbarui</dt>
                    <dd>{{ $task->updated_at->format('d/m/Y H:i') }}</dd>
                </div>
                @if ($task->completed_at)
                <div>
                    <dt class="text-xs uppercase text-slate-500">Diselesaikan</dt>
                    <dd>{{ $task->completed_at->format('d/m/Y H:i') }}</dd>
                </div>
                @endif
            </dl>
        </div>

        {{-- Aktivitas Tim (Kanban) --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-4 font-bold">Aktivitas Tim (Kanban)</h2>

            @if (empty($task->kanban_card_id))
                <p class="text-slate-500 dark:text-slate-400">Belum terhubung ke kanban.</p>
            @else
                <div class="space-y-4">
                    {{-- ID Kartu & Status --}}
                    <div class="flex flex-wrap items-center gap-3">
                        <div>
                            <dt class="text-xs uppercase text-slate-500">ID Kartu</dt>
                            <dd class="font-mono text-sm">{{ $task->kanban_card_id }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-slate-500">Status</dt>
                            <dd>
                                @php
                                    $statusColors = [
                                        'ready' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                                        'running' => 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
                                        'blocked' => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
                                        'done' => 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',
                                    ];
                                    $statusColor = $statusColors[$task->kanban_status] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
                                @endphp
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColor }}">
                                    {{ ucfirst($task->kanban_status) }}
                                </span>
                            </dd>
                        </div>
                        @if ($task->kanban_synced_at)
                        <div>
                            <dt class="text-xs uppercase text-slate-500">Disinkronkan</dt>
                            <dd class="text-slate-600 dark:text-slate-400">{{ $task->kanban_synced_at->format('d/m/Y H:i') }}</dd>
                        </div>
                        @endif
                    </div>

                    {{-- Summary Terakhir --}}
                    @if (!empty($task->kanban_summary))
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Summary Terakhir</dt>
                        <dd class="mt-1 whitespace-pre-wrap text-sm bg-slate-50 p-3 rounded-lg dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                            {{ e($task->kanban_summary) }}
                        </dd>
                    </div>
                    @endif

                    {{-- Daftar Komentar --}}
                    @if (!empty($task->kanban_comments))
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Komentar ({{ count($task->kanban_comments) }})</dt>
                        <div class="mt-2 space-y-3">
                            @foreach ($task->kanban_comments as $comment)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800">
                                <header class="flex flex-wrap items-center gap-2 mb-2 text-xs">
                                    <strong class="text-slate-800 dark:text-slate-200">{{ e($comment['author'] ?? '—') }}</strong>
                                    <span class="text-slate-400">·</span>
                                    <time>{{ \Carbon\Carbon::parse($comment['at'])->format('d/m/Y H:i') }}</time>
                                </header>
                                <div class="whitespace-pre-wrap text-sm">{{ e($comment['body'] ?? '') }}</div>
                            </article>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-3 font-bold">Aksi Cepat</h2>
            <div class="space-y-2">
                @if (!$task->status->isDone())
                    <form method="POST" action="{{ route('tasks.complete', $task) }}" class="inline">
                        @csrf @method('PATCH')
                        <button class="w-full rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 text-left">Tandai Selesai</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('tasks.reopen', $task) }}" class="inline">
                        @csrf @method('PATCH')
                        <button class="w-full rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 text-left">Buka Kembali</button>
                    </form>
                @endif
                <a href="{{ route('tasks.board') }}" class="block rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-center hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Lihat di Papan Kanban</a>
            </div>
        </div>
    </div>
</div>
@endsection