@extends('layouts.app')

@section('title', 'Tambah Layanan')

@section('content')
<x-page-header title="Tambah Layanan" back="{{ route('services.index') }}" backLabel="Kembali ke daftar" icon="wrench" />

<div class="max-w-3xl">
    <x-card>
        <form method="POST" action="{{ route('services.store') }}" class="space-y-4">
            @csrf
            @include('services._form', ['service' => null])

            <div class="flex gap-2 pt-2">
                <x-btn type="submit">Simpan</x-btn>
                <x-btn :href="route('services.index')" variant="outline">Batal</x-btn>
            </div>
        </form>
    </x-card>
</div>
@endsection