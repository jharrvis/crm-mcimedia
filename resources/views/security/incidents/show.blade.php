@extends('layouts.app')

@section('title', $incident->title)

@section('content')
<x-page-header :title="$incident->title" icon="shield-alert"
               back="{{ route('security.incidents.index') }}" backLabel="Kembali ke daftar insiden"
               :subtitle="$incident->client ? 'Klien: ' . $incident->client->name : null">
    <x-btn :href="route('security.incidents.edit', $incident)" icon="pencil">Ubah</x-btn>
</x-page-header>

<x-card :padded="true" class="max-w-4xl">
    <dl class="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
        <div>
            <dt class="font-medium text-slate-400">Status</dt>
            <dd class="mt-1">
                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $incident->status->badgeClass() }}">{{ $incident->status->label() }}</span>
            </dd>
        </div>
        <div>
            <dt class="font-medium text-slate-400">Keparahan</dt>
            <dd class="mt-1">
                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $incident->severity->badgeClass() }}">{{ $incident->severity->label() }}</span>
            </dd>
        </div>
        <div>
            <dt class="font-medium text-slate-400">Sumber</dt>
            <dd class="mt-1">{{ $incident->source->label() }}</dd>
        </div>
        <div>
            <dt class="font-medium text-slate-400">Waktu</dt>
            <dd class="mt-1">{{ $incident->occurred_at?->format('d/m/Y H:i') }}</dd>
        </div>
        @if ($incident->external_id)
            <div class="col-span-2 md:col-span-4">
                <dt class="font-medium text-slate-400">External ID</dt>
                <dd class="mt-1 break-all font-mono text-xs">{{ $incident->external_id }}</dd>
            </div>
        @endif
    </dl>
    @if ($incident->client)
        <p class="mt-4 text-sm text-slate-400">
            Klien:
            <a href="{{ route('clients.show', $incident->client) }}" class="font-semibold text-brand-600 hover:underline">{{ $incident->client->name }}</a>
        </p>
    @endif
    @if (filled($incident->description))
        <div class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
            <h2 class="mb-1 text-sm font-medium text-slate-400">Deskripsi</h2>
            <p class="whitespace-pre-line text-sm">{{ $incident->description }}</p>
        </div>
    @endif
</x-card>
@endsection