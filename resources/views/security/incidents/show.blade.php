@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto py-6 px-4">
    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route('security.incidents.index') }}" class="text-blue-600 hover:underline">&larr; Kembali ke daftar insiden</a>
        <a href="{{ route('security.incidents.edit', $incident) }}" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Edit</a>
    </div>
    <div class="bg-white dark:bg-slate-800 shadow rounded-lg p-6">
        <h1 class="text-xl font-bold mb-3">{{ $incident->title }}</h1>
        @if ($incident->client)
            <p class="text-sm text-gray-500 dark:text-slate-400 mb-4">
                Klien:
                <a href="{{ route('clients.show', $incident->client) }}" class="text-blue-600 hover:underline">{{ $incident->client->name }}</a>
            </p>
        @endif
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><dt class="font-medium text-gray-500 dark:text-slate-400">Status</dt><dd class="mt-1"><span class="px-2 py-1 rounded text-xs {{ $incident->status->badgeClass() }}">{{ $incident->status->label() }}</span></dd></div>
            <div><dt class="font-medium text-gray-500 dark:text-slate-400">Severity</dt><dd class="mt-1"><span class="px-2 py-1 rounded text-xs {{ $incident->severity->badgeClass() }}">{{ $incident->severity->label() }}</span></dd></div>
            <div><dt class="font-medium text-gray-500 dark:text-slate-400">Sumber</dt><dd class="mt-1">{{ $incident->source->label() }}</dd></div>
            <div><dt class="font-medium text-gray-500 dark:text-slate-400">Waktu</dt><dd class="mt-1">{{ $incident->occurred_at?->format('d/m/Y H:i') }}</dd></div>
            @if ($incident->external_id)
                <div class="col-span-2 md:col-span-4"><dt class="font-medium text-gray-500 dark:text-slate-400">External ID</dt><dd class="mt-1 font-mono text-xs break-all">{{ $incident->external_id }}</dd></div>
            @endif
        </dl>
        @if (filled($incident->description))
            <div class="border-t mt-4 pt-4">
                <h2 class="font-medium text-gray-500 dark:text-slate-400 text-sm mb-1">Deskripsi</h2>
                <p class="text-sm whitespace-pre-line">{{ $incident->description }}</p>
            </div>
        @endif
    </div>

</div>
@endsection
