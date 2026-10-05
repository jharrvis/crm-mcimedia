@extends('layouts.app')

@section('title', 'Detail Project')

@section('content')
@php $progress = $project->progressPercent(); @endphp

<div class="mb-4 flex items-center justify-between">
    <a href="{{ route('projects.index') }}" class="text-sm text-brand-600 hover:underline">← Kembali ke daftar</a>
    <div class="flex gap-2">
        <a href="{{ route('projects.reports.index', $project) }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Laporan pencapaian</a>
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
            <div><dt class="text-slate-500">Klien</dt><dd><a href="{{ route('clients.show', $project->client) }}" class="text-brand-600 hover:underline">{{ $project->client?->name ?? '—' }}</a></dd></div>
            <div>
                <dt class="text-slate-500">Status</dt>
                <dd>
                    <span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300">{{ $project->status->label() }}</span>
                    @if ($project->isOverdue())
                        <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300">Overdue</span>
                    @endif
                </dd>
            </div>
            <div><dt class="text-slate-500">Deadline</dt><dd class="{{ $project->isOverdue() ? 'font-semibold text-red-600' : '' }}">{{ tgl_id($project->deadline) }}</dd></div>
            <div><dt class="text-slate-500">Nilai project</dt><dd class="font-semibold">{{ rupiah($project->value) }}</dd></div>
            <div>
                <dt class="text-slate-500">Progress ({{ $project->doneTasksCount() }}/{{ $project->tasksCount() }} task)</dt>
                <dd class="mt-1 flex items-center gap-2">
                    <div class="h-2 w-32 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                        <div class="h-full rounded-full {{ $progress === 100 ? 'bg-green-500' : 'bg-brand-500' }}" style="width: {{ $progress }}%"></div>
                    </div>
                    <span class="text-sm font-semibold">{{ $progress }}%</span>
                </dd>
            </div>
        </dl>
        @if ($project->description)
            <div class="mt-4 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                <p class="mb-1 text-slate-500">Deskripsi</p>
                <p class="whitespace-pre-line">{{ $project->description }}</p>
            </div>
        @endif

        <div class="mt-4 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
            <div class="mb-2 flex items-center justify-between">
                <p class="text-slate-500">Laporan pencapaian</p>
                <a href="{{ route('projects.reports.index', $project) }}" class="text-xs text-brand-600 hover:underline">Lihat semua</a>
            </div>
            @forelse ($project->achievementReports->take(3) as $report)
                <p class="text-xs">
                    <a href="{{ route('projects.reports.show', [$project, $report]) }}" class="text-brand-600 hover:underline">{{ $report->periodLabel() }}</a>
                    <span class="text-slate-400">· {{ tgl_id($report->generated_at) }}</span>
                </p>
            @empty
                <p class="text-xs text-slate-400">Belum ada laporan.</p>
            @endforelse
        </div>
    </div>

    <div class="space-y-6 lg:col-span-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-bold">Tugas project ini ({{ $project->tasksCount() }})</h2>
                <div class="flex gap-2 text-sm">
                    <a href="{{ route('tasks.board', ['project_id' => $project->id]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Papan</a>
                    <a href="{{ route('tasks.create') }}" class="rounded-lg bg-brand-600 px-3 py-1.5 font-semibold text-white hover:bg-brand-700">Tambah tugas</a>
                </div>
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
                                    <td class="py-2 pr-4"><a href="{{ route('tasks.edit', $task) }}" class="font-medium hover:text-brand-600">{{ $task->title }}</a></td>
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
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div id="jurnal" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-bold">Jurnal progress ({{ $project->journals->count() }})</h2>
                <button type="button" data-toggle-journal
                        class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700">Tulis entri</button>
            </div>

            <form method="POST" action="{{ route('projects.journals.store', $project) }}"
                  data-journal-form class="mb-5 hidden">
                @csrf
                <div class="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800">
                @if ($errors->any() && old('_journal') === 'store')
                    <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        <ul class="list-inside list-disc">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif
                <div class="flex flex-wrap gap-2">
                    <input type="hidden" name="_journal" value="store">
                    <input type="date" name="occurred_on" value="{{ old('occurred_on', now()->toDateString()) }}" required
                           class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                    <select name="category" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                        @foreach (\App\Domains\Projects\Enums\JournalCategory::cases() as $category)
                            <option value="{{ $category->value }}" @selected(old('category') === $category->value)>{{ $category->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <textarea name="body" rows="3" required placeholder="Update apa yang sudah dikerjakan…"
                          class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">{{ old('body') }}</textarea>
                <div class="flex gap-2">
                    <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Simpan entri</button>
                </div>
                </div>
            </form>

            @if ($project->journals->isEmpty())
                <p class="text-sm text-slate-500">Belum ada catatan jurnal.</p>
            @else
                <ol class="relative space-y-4 border-l border-slate-200 pl-5 dark:border-slate-700">
                    @foreach ($project->journals as $journal)
                        <li class="relative">
                            <span class="absolute -left-[1.6rem] top-1.5 h-2.5 w-2.5 rounded-full {{ ['progress' => 'bg-green-500', 'note' => 'bg-slate-400', 'blocker' => 'bg-red-500'][$journal->category->value] }}"></span>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span class="font-semibold text-slate-700 dark:text-slate-200">{{ tgl_id($journal->occurred_on) }}</span>
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $journal->category->badgeClasses() }}">{{ $journal->category->label() }}</span>
                                <span>{{ $journal->author?->name ?? 'Sistem' }}</span>
                            </div>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ $journal->body }}</p>
                            <div class="mt-1 flex gap-2 text-xs">
                                <details class="inline">
                                    <summary class="cursor-pointer text-brand-600 hover:underline">Ubah</summary>
                                    <form method="POST" action="{{ route('projects.journals.update', [$project, $journal]) }}"
                                          class="mt-2 grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800">
                                        @csrf @method('PATCH')
                                        <div class="flex flex-wrap gap-2">
                                            <input type="date" name="occurred_on" value="{{ old('occurred_on', $journal->occurred_on->toDateString()) }}" required
                                                   class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                                            <select name="category" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                                                @foreach (\App\Domains\Projects\Enums\JournalCategory::cases() as $category)
                                                    <option value="{{ $category->value }}" @selected($journal->category === $category)>{{ $category->label() }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <textarea name="body" rows="2" required
                                                  class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">{{ $journal->body }}</textarea>
                                        <button class="justify-self-start rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">Simpan</button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('projects.journals.destroy', [$project, $journal]) }}" class="inline"
                                      onsubmit="return confirm('Hapus entri jurnal ini?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline">Hapus</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
</div>

<script>
    (function () {
        const toggle = document.querySelector('[data-toggle-journal]');
        const form = document.querySelector('[data-journal-form]');
        if (!toggle || !form) return;
        toggle.addEventListener('click', () => form.classList.toggle('hidden'));
        // Form dibuka lagi bila validasi sebelumnya gagal pada form jurnal.
        if ({{ $errors->any() && old('_journal') === 'store' ? 'true' : 'false' }}) {
            form.classList.remove('hidden');
        }
    })();
</script>
@endsection
