@extends('layouts.app')

@section('title', 'Jurnal Tindakan Keamanan')

@section('content')
<x-page-header title="Jurnal Tindakan Keamanan" icon="history"
               subtitle="Catatan tindakan teknis & konsultasi keamanan untuk setiap klien.">
    <x-btn :href="route('security.index')" variant="outline" icon="layout-dashboard">Dashboard</x-btn>
    <x-btn :href="route('security.actions.create', request('client_id') ? ['client_id' => request('client_id')] : [])" icon="plus">Catat tindakan</x-btn>
</x-page-header>

<x-card class="mb-4">
    <form method="GET" action="{{ route('security.actions.index') }}" class="flex flex-wrap items-end gap-3">
        <div class="w-64">
            <x-input name="client_id" label="Klien" type="select" :options="['' => 'Semua klien'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all()" :value="request('client_id')" />
        </div>
        <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
    </form>
</x-card>

<x-card>
    <x-table>
        <thead><tr>
            <th>Tanggal</th>
            <th>Klien</th>
            <th>Tindakan</th>
            <th>Dikerjakan oleh</th>
            <th>Hasil</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($actions as $action)
                <tr>
                    <td class="text-slate-400">{{ tgl_id($action->acted_at) }}</td>
                    <td>{{ $action->client?->name ?? '—' }}</td>
                    <td class="max-w-md">{{ \Illuminate\Support\Str::limit($action->action, 160) }}</td>
                    <td>{{ $action->performed_by ?? '—' }}</td>
                    <td class="max-w-md text-slate-400">{{ $action->result ? \Illuminate\Support\Str::limit($action->result, 120) : '—' }}</td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('security.actions.edit', $action) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('security.actions.destroy', $action) }}" class="inline"
                              onsubmit="return confirm('Hapus catatan tindakan ini?')">
                            @csrf
                            @method('DELETE')
                            <button class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">
                    <x-empty-state title="Belum ada catatan tindakan" icon="history">
                        Tindakan yang dicatat di sini bisa dikirim ke laporan keamanan klien.
                        <x-slot:action>
                            <x-btn :href="route('security.actions.create')" icon="plus">Catat tindakan</x-btn>
                        </x-slot:action>
                    </x-empty-state>
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($actions->hasPages())
        <div class="mt-4">{{ $actions->links() }}</div>
    @endif
</x-card>
@endsection