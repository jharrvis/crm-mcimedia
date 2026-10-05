@extends('layouts.app')

@section('title', 'Ubah Provider Domain')

@section('content')
<x-page-header title="Ubah provider: {{ $provider->name }}" back="{{ route('domain-providers.index') }}" backLabel="Kembali ke daftar" icon="globe">
    <x-btn :href="route('domain-providers.domains', $provider)" variant="outline">Lihat domain</x-btn>
</x-page-header>

@include('domain-providers._form', [
    'action' => route('domain-providers.update', $provider),
    'method' => 'PUT',
    'submitLabel' => 'Simpan perubahan',
])
@endsection