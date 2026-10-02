@extends('layouts.app')

@section('title', 'Laporan Pencapaian')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <a href="{{ route('projects.reports.index', $project) }}" class="text-sm text-indigo-600 hover:underline">← Kembali ke daftar laporan</a>
    <div class="flex gap-2">
        <a href="{{ route('projects.reports.pdf', [$project, $report]) }}"
           class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Unduh PDF</a>
        <form method="POST" action="{{ route('projects.reports.destroy', [$project, $report]) }}" onsubmit="return confirm('Hapus laporan ini?')">
            @csrf @method('DELETE')
            <button class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Hapus</button>
        </form>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <div class="border-b border-slate-100 pb-4 dark:border-slate-800">
        <p class="text-xs uppercase tracking-wide text-slate-500">Laporan pencapaian</p>
        <h2 class="text-xl font-bold">{{ $project->title }}</h2>
        <p class="text-sm text-slate-500">
            {{ $project->client?->name ?? '—' }} · {{ $report->periodLabel() }}
            · dibuat {{ tgl_id($report->generated_at ?? $report->created_at) }}
            oleh {{ $report->author?->name ?? 'Sistem' }}
        </p>
    </div>

    <div class="mt-4">
        <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Ringkasan otomatis</h3>
        <p class="whitespace-pre-line rounded-lg bg-slate-50 p-4 text-sm dark:bg-slate-800">{{ $report->summary ?: 'Ringkasan belum dihitung.' }}</p>
    </div>

    <div class="mt-6">
        <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Narasi</h3>
        @if ($report->narrative)
            <p class="whitespace-pre-line text-sm">{{ $report->narrative }}</p>
        @else
            <p class="text-sm text-slate-500">Belum ada narasi manual untuk laporan ini.</p>
        @endif
    </div>

    <div class="mt-6 grid gap-4 border-t border-slate-100 pt-4 text-sm sm:grid-cols-3 dark:border-slate-800">
        <div>
            <p class="text-slate-500">Status project</p>
            <p class="font-medium">{{ $project->status->label() }}</p>
        </div>
        <div>
            <p class="text-slate-500">Progress</p>
            <p class="font-medium">{{ $project->progressPercent() }}% ({{ $project->doneTasksCount() }}/{{ $project->tasksCount() }} task)</p>
        </div>
        <div>
            <p class="text-slate-500">Deadline</p>
            <p class="font-medium {{ $project->isOverdue() ? 'text-red-600' : '' }}">{{ tgl_id($project->deadline) }}</p>
        </div>
    </div>
</div>
@endsection
