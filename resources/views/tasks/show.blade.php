@extends('layouts.app')

@section('title', $task->title)

@section('content')
@php
    $statusVariant = ['open' => 'slate', 'in_progress' => 'warning', 'review' => 'info', 'done' => 'success'][$task->status->value];
    $prioVariant = ['high' => 'danger', 'medium' => 'warning', 'low' => 'slate'][$task->priority->value];
@endphp

<x-page-header :title="$task->title" icon="list-checks"
               :subtitle="'Due: ' . tgl_id($task->due_date) . ($task->isOverdue() ? ' · lewat deadline' : '')"
               back="{{ route('tasks.index') }}" backLabel="Kembali ke daftar">
    <x-btn :href="route('tasks.edit', $task)" variant="outline">Ubah</x-btn>
    <form method="POST" action="{{ route('tasks.destroy', $task) }}" onsubmit="return confirm('Hapus tugas ini?')">
        @csrf
        @method('DELETE')
        <x-btn type="submit" variant="danger">Hapus</x-btn>
    </form>
</x-page-header>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-card>
            <x-slot:header>
                <h2 class="font-bold">Detail Tugas</h2>
            </x-slot:header>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs uppercase text-slate-400">Deskripsi</dt>
                    <dd class="whitespace-pre-wrap">{{ $task->description ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Klien</dt>
                    <dd>
                        @if ($task->client)
                            <a href="{{ route('clients.show', $task->client) }}" class="text-brand-600 hover:underline">{{ $task->client->name }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Project</dt>
                    <dd>
                        @if ($task->project)
                            <a href="{{ route('projects.show', $task->project) }}" class="text-brand-600 hover:underline">{{ $task->project->title }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Penanggung jawab</dt>
                    <dd>{{ $task->assignee?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Prioritas</dt>
                    <dd><x-badge :variant="$prioVariant">{{ $task->priority->label() }}</x-badge></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Status</dt>
                    <dd><x-badge :variant="$statusVariant" :dot="true">{{ $task->status->label() }}</x-badge></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Deadline</dt>
                    <dd class="{{ $task->isOverdue() ? 'font-semibold text-red-600' : '' }}">
                        {{ tgl_id($task->due_date) }}
                        @if ($task->isOverdue()) · lewat deadline @endif
                    </dd>
                </div>
                <div><dt class="text-xs uppercase text-slate-400">Dibuat</dt><dd>{{ $task->created_at->format('d/m/Y H:i') }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-400">Diperbarui</dt><dd>{{ $task->updated_at->format('d/m/Y H:i') }}</dd></div>
                @if ($task->completed_at)
                    <div><dt class="text-xs uppercase text-slate-400">Diselesaikan</dt><dd>{{ $task->completed_at->format('d/m/Y H:i') }}</dd></div>
                @endif
            </dl>
        </x-card>

        {{-- Aktivitas Tim (Kanban) --}}
        <x-card>
            <x-slot:header>
                <h2 class="font-bold">Aktivitas Tim (Kanban)</h2>
            </x-slot:header>

            @if (empty($task->kanban_card_id))
                <p class="text-sm text-slate-500">Belum terhubung ke kanban.</p>
            @else
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <div>
                            <p class="text-xs uppercase text-slate-400">ID Kartu</p>
                            <p class="font-mono text-sm">{{ $task->kanban_card_id }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-slate-400">Status</p>
                            @php
                                // Kelas literal dipertahankan (TaskTest memassert bg-blue-100 dst — palet DS tidak punya biru/hijau).
                                $statusColors = [
                                    'ready' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                                    'running' => 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
                                    'blocked' => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
                                    'done' => 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',
                                ];
                                $statusColor = $statusColors[$task->kanban_status] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
                            @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusColor }}">
                                {{ ucfirst($task->kanban_status) }}
                            </span>
                        </div>
                        @if ($task->kanban_synced_at)
                            <div>
                                <p class="text-xs uppercase text-slate-400">Disinkronkan</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">{{ $task->kanban_synced_at->format('d/m/Y H:i') }}</p>
                            </div>
                        @endif
                    </div>

                    @if (!empty($task->kanban_summary))
                        <div>
                            <p class="text-xs uppercase text-slate-400">Summary Terakhir</p>
                            <p class="mt-1 whitespace-pre-wrap rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm dark:border-slate-700 dark:bg-slate-800">{{ e($task->kanban_summary) }}</p>
                        </div>
                    @endif

                    @if (!empty($task->kanban_comments))
                        <div>
                            <p class="text-xs uppercase text-slate-400">Komentar ({{ count($task->kanban_comments) }})</p>
                            <div class="mt-2 space-y-3">
                                @foreach ($task->kanban_comments as $comment)
                                    <article class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800">
                                        <header class="mb-2 flex flex-wrap items-center gap-2 text-xs">
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
        </x-card>
    </div>

    <div>
        <x-card>
            <x-slot:header>
                <h2 class="font-bold">Aksi Cepat</h2>
            </x-slot:header>
            <div class="space-y-2">
                @if (!$task->status->isDone())
                    <form method="POST" action="{{ route('tasks.complete', $task) }}">
                        @csrf
                        @method('PATCH')
                        <x-btn type="submit" class="w-full justify-start">Tandai Selesai</x-btn>
                    </form>
                @else
                    <form method="POST" action="{{ route('tasks.reopen', $task) }}">
                        @csrf
                        @method('PATCH')
                        <x-btn type="submit" variant="outline" class="w-full justify-start">Buka Kembali</x-btn>
                    </form>
                @endif
                <x-btn :href="route('tasks.board')" variant="outline" class="w-full justify-center">Lihat di Papan Kanban</x-btn>
            </div>
        </x-card>
    </div>
</div>
@endsection
