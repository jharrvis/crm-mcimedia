@extends('layouts.app')

@section('title', 'Laporan Keamanan')

@section('content')
@php $inputClass = 'rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800'; @endphp

<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <form method="GET" action="{{ route('security.reports.index') }}" class="flex items-end gap-2">
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-500">Klien</label>
            <select name="client_id" class="{{ $inputClass }}">
                <option value="">Semua klien</option>
                @foreach ($clients as $c)
                    <option value="{{ $c->id }}" @selected((string) request('client_id') === (string) $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Filter</button>
    </form>
    <div class="flex gap-2">
        <a href="{{ route('security.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Dashboard</a>
        <a href="{{ route('security.reports.create', request('client_id') ? ['client_id' => request('client_id')] : []) }}"
           class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Unggah laporan</a>
    </div>
</div>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Periode</th>
                <th class="px-4 py-3">Klien</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Terkirim</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($reports as $report)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 font-medium">{{ $report->period }}</td>
                    <td class="px-4 py-3">{{ $report->client?->name ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $report->status->badgeClass() }}">{{ $report->status->label() }}</span>
                    </td>
                    <td class="px-4 py-3 text-slate-500">{{ $report->sent_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        @if ($report->hasFile())
                            <a href="{{ route('security.reports.download', $report) }}" class="text-sm text-brand-600 hover:underline">Unduh</a>
                            <form method="POST" action="{{ route('security.reports.send-to-client', $report) }}" class="inline"
                                  onsubmit="return confirm('Kirim laporan periode {{ $report->period }} ke email kontak klien?')">
                                @csrf
                                @method('PATCH')
                                <button class="ml-2 text-sm text-green-700 hover:underline dark:text-green-400">Kirim ke Klien</button>
                            </form>
                        @endif
                        @if ($report->status === \App\Domains\Security\Enums\ReportStatus::Draft)
                            <form method="POST" action="{{ route('security.reports.send', $report) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <button class="ml-2 text-sm text-green-700 hover:underline dark:text-green-400">Tandai terkirim</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('security.reports.destroy', $report) }}" class="inline"
                              onsubmit="return confirm('Hapus laporan periode {{ $report->period }}?')">
                            @csrf
                            @method('DELETE')
                            <button class="ml-2 text-sm text-red-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Belum ada laporan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $reports->links() }}</div>
@endsection
