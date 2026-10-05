@extends('layouts.app')

@section('title', 'Laporan Pencapaian')

@section('content')
@php
    $periodOptions = collect($periods)->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
@endphp

<x-page-header title="Laporan pencapaian" icon="file-text"
               :subtitle="$project->title . ' · ' . ($project->client?->name ?? '—')"
               back="{{ route('projects.show', $project) }}" backLabel="Kembali ke project" />

<div class="grid gap-6 lg:grid-cols-3">
    <x-card>
        <x-slot:header>
            <h2 class="font-bold">Buat laporan dari data periode</h2>
        </x-slot:header>
        <p class="mb-3 text-xs text-slate-500">
            Ringkasan dihitung otomatis dari task yang selesai dan entri jurnal pada periode tersebut.
            Generate ulang periode yang sama akan menyegarkan ringkasan (tidak menggandakan laporan).
        </p>
        <form method="POST" action="{{ route('projects.reports.store', $project) }}" class="space-y-3">
            @csrf
            @if ($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif
            <x-input name="period_type" label="Periode" type="select" :options="$periodOptions" :value="old('period_type', 'month')" />
            <x-input name="anchor" label="Tanggal acuan (di dalam periode)" type="date" :value="old('anchor', now()->toDateString())" />
            <x-input name="narrative" label="Narasi (opsional)" type="textarea" rows="4"
                     placeholder="Narasi manual untuk laporan ini…" :value="old('narrative')" />
            <x-btn type="submit">Buat laporan</x-btn>
        </form>
    </x-card>

    <x-card class="lg:col-span-2">
        <x-slot:header>
            <h2 class="font-bold">Riwayat laporan ({{ $reports->count() }})</h2>
        </x-slot:header>

        @if ($reports->isEmpty())
            <x-empty-state title="Belum ada laporan untuk project ini" icon="file-text">
                Buat laporan pertama dari data periode di samping.
            </x-empty-state>
        @else
            <x-table>
                <thead><tr>
                    <th>Periode</th>
                    <th>Dibuat</th>
                    <th>Penulis</th>
                    <th class="text-right">Aksi</th>
                </tr></thead>
                <tbody>
                    @foreach ($reports as $report)
                        <tr>
                            <td>
                                <a href="{{ route('projects.reports.show', [$project, $report]) }}" class="font-semibold hover:text-brand-600">{{ $report->periodLabel() }}</a>
                                @if ($report->narrative)<x-badge variant="slate" class="ml-1">ada narasi</x-badge>@endif
                            </td>
                            <td class="text-slate-400">{{ tgl_id($report->generated_at ?? $report->created_at) }}</td>
                            <td class="text-slate-400">{{ $report->author?->name ?? 'Sistem' }}</td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('projects.reports.show', [$project, $report]) }}" class="text-sm font-semibold text-brand-600 hover:underline">Lihat</a>
                                <span class="mx-1 text-slate-300">|</span>
                                <a href="{{ route('projects.reports.pdf', [$project, $report]) }}" class="text-sm font-semibold text-brand-600 hover:underline">Unduh PDF</a>
                                <span class="mx-1 text-slate-300">|</span>
                                <form method="POST" action="{{ route('projects.reports.destroy', [$project, $report]) }}" class="inline"
                                      onsubmit="return confirm('Hapus laporan ini?')">
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
</div>
@endsection
