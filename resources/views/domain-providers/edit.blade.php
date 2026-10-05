@extends('layouts.app')

@section('title', 'Ubah Provider Domain')

@section('content')
<div class="mb-4 flex items-center justify-between">
    <h1 class="text-lg font-semibold">Ubah provider: {{ $provider->name }}</h1>
    <a href="{{ route('domain-providers.domains', $provider) }}" class="text-sm text-brand-600 hover:underline">Lihat domain</a>
</div>

@include('domain-providers._form', [
    'action' => route('domain-providers.update', $provider),
    'method' => 'PUT',
    'submitLabel' => 'Simpan perubahan',
])
@endsection
