@extends('layouts.app')

@section('title', 'Domain Provider')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <div>
        <h1 class="text-lg font-semibold">Domain dari {{ $provider->name }}</h1>
        <p class="text-sm text-slate-500">Driver: {{ $provider->driverLabel() }} · ditarik lewat driver provider (listDomains + getExpiry).</p>
    </div>
    <div class="flex items-center gap-3">
        <a href="{{ route('domain-providers.domains', $provider) }}" class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-700">Muat ulang</a>
        <a href="{{ route('domain-providers.edit', $provider) }}" class="text-sm text-indigo-600 hover:underline">Ubah provider</a>
        <a href="{{ route('domain-providers.index') }}" class="text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">Kembali</a>
    </div>
</div>

@if ($error)
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
        <p class="font-semibold">Gagal menarik domain dari provider.</p>
        <p class="mt-1">{{ $error }}</p>
    </div>
@endif

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Domain</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Kedaluwarsa</th>
                <th class="px-4 py-3">Sisa hari</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                @php $info = $item['info']; $expiry = $item['expiry']; $days = $info->daysUntilExpiry(); @endphp
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 font-medium">{{ $info->domain }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $info->status === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200' }}">
                            {{ ucfirst($info->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3">{{ $expiry?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if ($days === null)
                            <span class="text-slate-400">—</span>
                        @elseif ($days < 0)
                            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900 dark:text-red-200">Lewat {{ abs($days) }} hari</span>
                        @elseif ($days <= 30)
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900 dark:text-amber-200">{{ $days }} hari</span>
                        @else
                            {{ $days }} hari
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">{{ $error ? 'Data tidak tersedia.' : 'Tidak ada domain ditemukan.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
