@extends('layouts.app')

@section('title', 'Domain Provider')

@section('content')
<x-page-header title="Domain dari {{ $provider->name }}" icon="globe"
               subtitle="Driver: {{ $provider->driverLabel() }} · ditarik lewat driver provider (listDomains + getExpiry).">
    <x-btn :href="route('domain-providers.domains', $provider)" variant="dark" icon="refresh-cw">Muat ulang</x-btn>
    <x-btn :href="route('domain-providers.edit', $provider)" variant="outline">Ubah provider</x-btn>
    <x-btn :href="route('domain-providers.index')" variant="outline">Kembali</x-btn>
</x-page-header>

@if ($error)
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
        <p class="font-semibold">Gagal menarik domain dari provider.</p>
        <p class="mt-1">{{ $error }}</p>
    </div>
@endif

{{-- Impor domain provider menjadi data layanan CRM (F4-7). --}}
<x-card class="mb-4">
    <form method="POST" action="{{ route('domain-providers.import-services', $provider) }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <div class="w-64">
            <label for="client_id" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Impor sebagai layanan milik klien</label>
            <select id="client_id" name="client_id" required
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                <option value="">— pilih klien —</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                @endforeach
            </select>
        </div>
        <x-btn type="submit" :disabled="! count($clients)">Impor jadi layanan</x-btn>
        <p class="basis-full text-xs text-slate-400">
            Domain yang sudah jadi layanan tidak diduplikasi; hanya tanggal kedaluwarsa yang diperbarui.
            @unless (count($clients))
                <span class="block text-amber-600 dark:text-amber-400">Belum ada klien aktif.</span>
            @endunless
        </p>
    </form>
</x-card>

<x-card>
    <x-table>
        <thead><tr>
            <th>Domain</th>
            <th>Status</th>
            <th>Kedaluwarsa</th>
            <th>Sisa hari</th>
            @if ($supportsAutoRenew)
                <th>Auto-renew</th>
            @endif
        </tr></thead>
        <tbody>
            @forelse ($items as $item)
                @php $info = $item['info']; $expiry = $item['expiry']; $days = $info->daysUntilExpiry(); @endphp
                <tr>
                    <td class="font-semibold">{{ $info->domain }}</td>
                    <td>
                        <x-badge :variant="$info->status === 'active' ? 'success' : 'danger'" :dot="true">{{ ucfirst($info->status) }}</x-badge>
                    </td>
                    <td>{{ $expiry?->format('d/m/Y') ?? '—' }}</td>
                    <td>
                        @if ($days === null)
                            <span class="text-slate-400">—</span>
                        @elseif ($days < 0)
                            <x-badge variant="danger">Lewat {{ abs($days) }} hari</x-badge>
                        @elseif ($days <= 30)
                            <x-badge variant="warning">{{ $days }} hari</x-badge>
                        @else
                            {{ $days }} hari
                        @endif
                    </td>
                    @if ($supportsAutoRenew)
                        <td>
                            @php $autoRenew = $item['auto_renew'] ?? null; @endphp
                            @if ($autoRenew === null)
                                <span class="text-slate-400">—</span>
                            @else
                                {{-- Kirim nilai kebalikan dari state saat ini. --}}
                                <form method="POST" action="{{ route('domain-providers.auto-renew', $provider) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="domain" value="{{ $info->domain }}">
                                    <input type="hidden" name="enable" value="{{ $autoRenew ? 0 : 1 }}">
                                    <button type="submit">
                                        <x-badge :variant="$autoRenew ? 'success' : 'slate'" :dot="true" class="hover:opacity-75">
                                            {{ $autoRenew ? 'Aktif' : 'Nonaktif' }}
                                        </x-badge>
                                    </button>
                                </form>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $supportsAutoRenew ? 5 : 4 }}">
                    <x-empty-state title="Tidak ada domain" icon="globe"
                                   :description="$error ? 'Data tidak tersedia.' : 'Tidak ada domain ditemukan.'" />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($items->hasPages())
        <div class="mt-4">{{ $items->links() }}</div>
    @endif
</x-card>
@endsection