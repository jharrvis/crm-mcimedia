@extends('layouts.app')

@section('title', 'Laporan Pencapaian')

@section('content')
<div class="mb-4 flex items-center justify-between">
    <a href="{{ route('projects.show', $project) }}" class="text-sm text-indigo-600 hover:underline">← Kembali ke project</a>
    <span class="text-sm text-slate-500">{{ $project->client?->name ?? '—' }}</span>
</div>

<h2 class="mb-4 text-lg font-bold">Laporan pencapaian — {{ $project->title }}</h2>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h3 class="mb-3 font-bold">Buat laporan dari data periode</h3>
        <p class="mb-3 text-xs text-slate-500">
            Ringkasan dihitung otomatis dari task yang selesai dan entri jurnal pada periode tersebut.
            Generate ulang periode yang sama akan menyegarkan ringkasan (tidak menggandakan laporan).
        </p>
        <form method="POST" action="{{ route('projects.reports.store', $project) }}" class="grid gap-3 text-sm">
            @csrf
            @if ($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif
            <label class="grid gap-1">
                <span class="text-slate-500">Periode</span>
                <select name="period_type" class="rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800">
                    @foreach ($periods as $period)
                        <option value="{{ $period->value }}" @selected(old('period_type', 'month') === $period->value)>{{ $period->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1">
                <span class="text-slate-500">Tanggal acuan (di dalam periode)</span>
                <input type="date" name="anchor" value="{{ old('anchor', now()->toDateString()) }}"
                       class="rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800">
            </label>
            <label class="grid gap-1">
                <span class="text-slate-500">Narasi (opsional)</span>
                <textarea name="narrative" rows="4" maxlength="5000" placeholder="Narasi manual untuk laporan ini…"
                          class="rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800">{{ old('narrative') }}</textarea>
            </label>
            <button class="rounded-lg bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-700">Buat laporan</button>
        </form>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 lg:col-span-2 dark:border-slate-800 dark:bg-slate-900">
        <h3 class="mb-3 font-bold">Riwayat laporan ({{ $reports->count() }})</h3>
        @if ($reports->isEmpty())
            <p class="text-sm text-slate-500">Belum ada laporan untuk project ini.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase text-slate-500">
                        <th class="py-2 pr-4">Periode</th><th class="py-2 pr-4">Dibuat</th><th class="py-2 pr-4">Penulis</th><th class="py-2">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($reports as $report)
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="py-2 pr-4">
                                    <a href="{{ route('projects.reports.show', [$project, $report]) }}" class="font-medium text-indigo-600 hover:underline">{{ $report->periodLabel() }}</a>
                                    @if ($report->narrative)<span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">ada narasi</span>@endif
                                </td>
                                <td class="py-2 pr-4 text-slate-500">{{ tgl_id($report->generated_at ?? $report->created_at) }}</td>
                                <td class="py-2 pr-4 text-slate-500">{{ $report->author?->name ?? 'Sistem' }}</td>
                                <td class="whitespace-nowrap py-2">
                                    <a href="{{ route('projects.reports.show', [$project, $report]) }}" class="text-indigo-600 hover:underline">Lihat</a>
                                    <span class="text-slate-300"> · </span>
                                    <a href="{{ route('projects.reports.pdf', [$project, $report]) }}" class="text-indigo-600 hover:underline">Unduh PDF</a>
                                    <span class="text-slate-300"> · </span>
                                    <form method="POST" action="{{ route('projects.reports.destroy', [$project, $report]) }}" class="inline"
                                          onsubmit="return confirm('Hapus laporan ini?')">
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
