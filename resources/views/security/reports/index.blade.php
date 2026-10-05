@extends('layouts.app')

@section('title', 'Laporan Keamanan')

@section('content')
<x-page-header title="Laporan Keamanan" icon="file-text"
               subtitle="Berkas laporan PDF per periode, dikirim ke klien atau diakses via portal.">
    <x-btn :href="route('security.index')" variant="outline" icon="layout-dashboard">Dashboard</x-btn>
    <x-btn :href="route('security.reports.create', request('client_id') ? ['client_id' => request('client_id')] : [])" icon="plus">Unggah laporan</x-btn>
</x-page-header>

<x-card class="mb-4">
    <form method="GET" action="{{ route('security.reports.index') }}" class="flex flex-wrap items-end gap-3">
        <div class="w-64">
            <x-input name="client_id" label="Klien" type="select"
                     :options="['' => 'Semua klien'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all()"
                     :value="request('client_id')" />
        </div>
        <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
    </form>
</x-card>

<x-card>
    <x-table>
        <thead><tr>
            <th>Periode</th>
            <th>Klien</th>
            <th>Status</th>
            <th>Terkirim</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($reports as $report)
                <tr>
                    <td class="font-semibold">{{ $report->period }}</td>
                    <td>{{ $report->client?->name ?? '—' }}</td>
                    <td>
                        <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $report->status->badgeClass() }}">{{ $report->status->label() }}</span>
                    </td>
                    <td class="text-slate-400">{{ $report->sent_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="whitespace-nowrap text-right">
                        @if ($report->hasFile())
                            <a href="{{ route('security.reports.download', $report) }}" class="text-sm font-semibold text-brand-600 hover:underline">Unduh</a>
                            <span class="mx-1 text-slate-300">|</span>
                            <form method="POST" action="{{ route('security.reports.send-to-client', $report) }}" class="inline"
                                  onsubmit="return confirm('Kirim laporan periode {{ $report->period }} ke email kontak klien?')">
                                @csrf
                                @method('PATCH')
                                <button class="text-sm font-semibold text-emerald-600 hover:underline">Kirim ke Klien</button>
                            </form>
                        @endif
                        @if ($report->status === \App\Domains\Security\Enums\ReportStatus::Draft)
                            <span class="mx-1 text-slate-300">|</span>
                            <form method="POST" action="{{ route('security.reports.send', $report) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <button class="text-sm font-semibold text-emerald-600 hover:underline">Tandai terkirim</button>
                            </form>
                        @endif
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('security.reports.destroy', $report) }}" class="inline"
                              onsubmit="return confirm('Hapus laporan periode {{ $report->period }}?')">
                            @csrf
                            @method('DELETE')
                            <button class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">
                    <x-empty-state title="Belum ada laporan" icon="file-text"
                                   description="Unggah laporan PDF per periode untuk dikirim ke klien atau ditampilkan di portal." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($reports->hasPages())
        <div class="mt-4">{{ $reports->links() }}</div>
    @endif
</x-card>
@endsection