@extends('layouts.app')

@section('title', 'Ubah Klien')

@section('content')
<x-page-header title="Ubah Klien" :back="route('clients.show', $client)" backLabel="Kembali ke detail" icon="users" />

<div class="max-w-2xl">
    <x-card>
        <form method="POST" action="{{ route('clients.update', $client) }}" class="space-y-4">
            @csrf
            @method('PUT')
            @include('clients._form', ['client' => $client])

            <div class="flex gap-2 pt-2">
                <x-btn type="submit">Simpan</x-btn>
                <x-btn :href="route('clients.show', $client)" variant="outline">Batal</x-btn>
            </div>
        </form>
    </x-card>
</div>
@endsection
