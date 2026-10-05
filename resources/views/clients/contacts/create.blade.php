@extends('layouts.app')

@section('title', 'Tambah Kontak')

@section('content')
<x-page-header title="Tambah Kontak" back="{{ route('clients.show', $client) }}" backLabel="Kembali ke detail klien" icon="users"
               subtitle="Klien: {{ $client->name }}" />

<div class="max-w-xl">
    <x-card>
        <form method="POST" action="{{ route('clients.contacts.store', $client) }}" class="space-y-4">
            @csrf
            @include('clients.contacts._form', ['contact' => null])

            <div class="flex gap-2 pt-2">
                <x-btn type="submit">Simpan</x-btn>
                <x-btn :href="route('clients.show', $client)" variant="outline">Batal</x-btn>
            </div>
        </form>
    </x-card>
</div>
@endsection