@extends('layouts.app')

@section('title', 'Tambah Klien')

@section('content')
<x-page-header title="Tambah Klien" back="{{ route('clients.index') }}" backLabel="Kembali ke daftar" icon="users" />

<div class="max-w-2xl">
    <x-card>
        <form method="POST" action="{{ route('clients.store') }}" class="space-y-4">
            @csrf
            @include('clients._form', ['client' => null])

            <div class="flex gap-2 pt-2">
                <x-btn type="submit">Simpan</x-btn>
                <x-btn :href="route('clients.index')" variant="outline">Batal</x-btn>
            </div>
        </form>
    </x-card>
</div>
@endsection
