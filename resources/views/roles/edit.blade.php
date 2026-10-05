@extends('layouts.app')

@section('title', 'Ubah Role')

@section('content')
@php
    $levelOptions = collect($levels)->mapWithKeys(fn ($l) => [$l->value => $l->label()])->all();
@endphp

<x-page-header :title="'Ubah Role — ' . $role->label" icon="shield-check"
               back="{{ route('roles.index') }}" backLabel="Kembali ke daftar role"
               subtitle="Perubahan hak akses berlaku langsung untuk pengguna dengan role ini." />

<x-card class="max-w-3xl">
    <form method="POST" action="{{ route('roles.update', $role) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('roles._form', ['role' => $role, 'levels' => $levels, 'levelOptions' => $levelOptions])
        <div class="flex gap-2 pt-2">
            <x-btn type="submit">Simpan</x-btn>
            <x-btn :href="route('roles.index')" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>
@endsection
