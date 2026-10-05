@extends('layouts.app')

@section('title', 'Ubah Kontak')

@section('content')
<x-page-header title="Ubah Kontak" :back="route('clients.show', $client)" backLabel="Kembali ke detail klien" icon="users"
               subtitle="Klien: {{ $client->name }}" />

<div class="max-w-xl">
    <x-card>
        <form method="POST" action="{{ route('clients.contacts.update', [$client, $contact]) }}" class="space-y-4">
            @csrf
            @method('PUT')
            @include('clients.contacts._form', ['contact' => $contact])

            <div class="flex gap-2 pt-2">
                <x-btn type="submit">Simpan</x-btn>
                <x-btn :href="route('clients.show', $client)" variant="outline">Batal</x-btn>
            </div>
        </form>
    </x-card>
</div>
@endsection