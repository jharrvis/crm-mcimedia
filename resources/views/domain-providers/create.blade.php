@extends('layouts.app')

@section('title', 'Tambah Provider Domain')

@section('content')
<x-page-header title="Tambah provider domain/hosting" back="{{ route('domain-providers.index') }}" backLabel="Kembali ke daftar" icon="globe" />

@include('domain-providers._form', [
    'action' => route('domain-providers.store'),
    'method' => null,
    'submitLabel' => 'Simpan provider',
])
@endsection