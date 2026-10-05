@extends('layouts.app')

@section('title', 'Detail Project')

@section('content')
@php
    $progress = $project->progressPercent();
    $journalCategoryOptions = collect(\App\Domains\Projects\Enums\JournalCategory::cases())
        ->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $journalCategoryOptionsEdit = collect(\App\Domains\Projects\Enums\JournalCategory::cases())
        ->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
@endphp

<x-page-header :title="$project->title" icon="folder-kanban"
               back="{{ route('projects.index') }}" backLabel="Kembali ke daftar">
    <x-btn :href="route('projects.reports.index', $project)" variant="outline" icon="file-text">Laporan pencapaian</x-btn>
    <x-btn :href="route('projects.edit', $project)" variant="outline">Ubah</x-btn>
    <form method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm('Hapus project ini?')">
        @csrf
        @method('DELETE')
        <x-btn type="submit" variant="danger">Hapus</x-btn>
    </form>
</x-page-header>

<div class="grid gap-6 lg:grid-cols-3">
    <x-card>
        <x-slot:header>
            <h2 class="font-bold">Info project</h2>
        </x-slot:header>
        <dl class="space-y-2 text-sm">
            <div><dt class="text-xs uppercase text-slate-400">Klien</dt><dd><a href="{{ route('clients.show', $project->client) }}" class="text-brand-600 hover:underline">{{ $project->client?->name ?? '—' }}</a></dd></div>
            <div>
                <dt class="text-xs uppercase text-slate-400">Status</dt>
                <dd>
                    <x-badge variant="info">{{ $project->status->label() }}</x-badge>
                    @if ($project->isOverdue())
                        <x-badge variant="danger" :dot="true" class="ml-1">Overdue</x-badge>
                    @endif
                </dd>
            </div>
            <div><dt class="text-xs uppercase text-slate-400">Deadline</dt><dd class="{{ $project->isOverdue() ? 'font-semibold text-red-600' : '' }}">{{ tgl_id($project->deadline) }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Nilai project</dt><dd class="font-semibold">{{ rupiah($project->value) }}</dd></div>
            <div>
                <dt class="text-xs uppercase text-slate-400">Progress ({{ $project->doneTasksCount() }}/{{ $project->tasksCount() }} task)</dt>
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
                <p class="mb-1 text-xs uppercase text-slate-400">Deskripsi</p>
                <p class="whitespace-pre-line">{{ $project->description }}</p>
            </div>
        @endif

        <div class="mt-4 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
            <div class="mb-2 flex items-center justify-between">
                <p class="text-xs uppercase text-slate-400">Laporan pencapaian</p>
                <a href="{{ route('projects.reports.index', $project) }}" class="text-xs font-semibold text-brand-600 hover:underline">Lihat semua</a>
            </div>
            @forelse ($project->achievementReports->take(3) as $report)
                <p class="text-xs">
                    <a href="{{ route('projects.reports.show', [$project, $report]) }}" class="font-semibold text-brand-600 hover:underline">{{ $report->periodLabel() }}</a>
                    <span class="text-slate-400">· {{ tgl_id($report->generated_at) }}</span>
                </p>
            @empty
                <p class="text-xs text-slate-400">Belum ada laporan.</p>
            @endforelse
        </div>
    </x-card>

    <div class="space-y-6 lg:col-span-2">
        <x-card>
            <x-slot:header>
                <h2 class="font-bold">Tugas project ini ({{ $project->tasksCount() }})</h2>
                <div class="ml-auto flex gap-2">
                    <x-btn :href="route('tasks.board', ['project_id' => $project->id])" variant="outline" icon="layout-grid">Papan</x-btn>
                    <x-btn :href="route('tasks.create')" icon="plus">Tambah tugas</x-btn>
                </div>
            </x-slot:header>

            @if ($project->tasks->isEmpty())
                <x-empty-state title="Belum ada tugas untuk project ini" icon="list-checks">
                    Buat tugas pertama untuk project ini agar progresnya tercatat.
                    <x-slot:action>
                        <x-btn :href="route('tasks.create')" icon="plus">Tambah tugas</x-btn>
                    </x-slot:action>
                </x-empty-state>
            @else
                <x-table>
                    <thead><tr>
                        <th>Judul</th>
                        <th>Prioritas</th>
                        <th>Due date</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($project->tasks->sortBy('due_date') as $task)
                            @php
                                $prioVariant = ['high' => 'danger', 'medium' => 'warning', 'low' => 'slate'][$task->priority->value];
                                $statusVariant = ['open' => 'slate', 'in_progress' => 'warning', 'review' => 'info', 'done' => 'success'][$task->status->value];
                            @endphp
                            <tr>
                                <td><a href="{{ route('tasks.edit', $task) }}" class="font-semibold hover:text-brand-600">{{ $task->title }}</a></td>
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
                        @endforeach
                    </tbody>
                </x-table>
            @endif
        </x-card>

        <x-card id="jurnal">
            <x-slot:header>
                <h2 class="font-bold">Jurnal progress ({{ $project->journals->count() }})</h2>
                <button type="button" data-toggle-journal
                        class="ml-auto rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700">Tulis entri</button>
            </x-slot:header>

            <form method="POST" action="{{ route('projects.journals.store', $project) }}"
                  data-journal-form class="mb-5 hidden">
                @csrf
                <div class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800">
                @if ($errors->any() && old('_journal') === 'store')
                    <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        <ul class="list-inside list-disc">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif
                <input type="hidden" name="_journal" value="store">
                <div class="flex flex-wrap gap-2">
                    <x-input name="occurred_on" label="Tanggal" type="date" :required="true" :value="old('occurred_on', now()->toDateString())" />
                    <x-input name="category" label="Kategori" type="select" :options="$journalCategoryOptions" :value="old('category')" />
                </div>
                <x-input name="body" label="Update" type="textarea" rows="3" :required="true"
                         placeholder="Update apa yang sudah dikerjakan…" :value="old('body')" />
                <div class="flex gap-2">
                    <x-btn type="submit">Simpan entri</x-btn>
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
                                <x-badge :variant="['progress' => 'success', 'note' => 'slate', 'blocker' => 'danger'][$journal->category->value]">{{ $journal->category->label() }}</x-badge>
                                <span>{{ $journal->author?->name ?? 'Sistem' }}</span>
                            </div>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ $journal->body }}</p>
                            <div class="mt-1 flex gap-2 text-xs">
                                <details class="inline">
                                    <summary class="cursor-pointer font-semibold text-brand-600 hover:underline">Ubah</summary>
                                    <form method="POST" action="{{ route('projects.journals.update', [$project, $journal]) }}"
                                          class="mt-2 grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800">
                                        @csrf
                                        @method('PATCH')
                                        <div class="flex flex-wrap gap-2">
                                            <x-input name="occurred_on" label="Tanggal" type="date" :required="true" :value="$journal->occurred_on->toDateString()" />
                                            <x-input name="category" label="Kategori" type="select" :options="$journalCategoryOptionsEdit"
                                                     :value="$journal->category->value" />
                                        </div>
                                        <x-input name="body" label="Isi" type="textarea" rows="2" :required="true" :value="$journal->body" />
                                        <x-btn type="submit" class="justify-self-start">Simpan</x-btn>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('projects.journals.destroy', [$project, $journal]) }}" class="inline"
                                      onsubmit="return confirm('Hapus entri jurnal ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-rose-600 hover:underline">Hapus</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-card>
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
