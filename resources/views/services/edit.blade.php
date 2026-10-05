@extends('layouts.app')

@section('title', 'Ubah Layanan')

@section('content')
<x-page-header title="Ubah Layanan" :back="route('services.show', $service)" backLabel="Kembali ke detail" icon="wrench" />

<div class="max-w-3xl">
    <x-card>
        <form method="POST" action="{{ route('services.update', $service) }}" class="space-y-4">
            @csrf
            @method('PUT')
            @include('services._form', ['service' => $service])

            <div class="flex gap-2 pt-2">
                <x-btn type="submit">Simpan perubahan</x-btn>
                <x-btn :href="route('services.show', $service)" variant="outline">Batal</x-btn>
            </div>
        </form>
    </x-card>
</div>
@endsection