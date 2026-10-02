@extends('layouts.app')

@section('title', 'Tambah Provider Domain')

@section('content')
<h1 class="mb-4 text-lg font-semibold">Tambah provider domain/hosting</h1>

@include('domain-providers._form', [
    'action' => route('domain-providers.store'),
    'method' => null,
    'submitLabel' => 'Simpan provider',
])
@endsection
