@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto py-6 px-4">
    <div class="mb-4">
        <a href="{{ route('security.incidents.index') }}" class="text-blue-600 hover:underline">&larr; Kembali ke daftar insiden</a>
    </div>
    <div class="bg-white shadow rounded-lg p-6">
        <h1 class="text-xl font-bold mb-4">{{ $incident->title }}</h1>
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div><dt class="font-medium text-gray-500">Status</dt><dd>{{ $incident->status->value }}</dd></div>
            <div><dt class="font-medium text-gray-500">Severity</dt><dd>{{ $incident->severity->value }}</dd></div>
            <div><dt class="font-medium text-gray-500">Source</dt><dd>{{ $incident->source->value }}</dd></div>
            <div><dt class="font-medium text-gray-500">Waktu</dt><dd>{{ $incident->occurred_at }}</dd></div>
        </dl>
        <div class="mt-4"><a href="{{ route('security.incidents.edit', $incident) }}" class="px-4 py-2 bg-blue-600 text-white rounded">Edit</a></div>
    </div>
</div>
@endsection
