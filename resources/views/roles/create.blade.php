@extends('layouts.app')

@section('title', 'Tambah Role')

@section('content')
@php
    $levelOptions = collect($levels)->mapWithKeys(fn ($l) => [$l->value => $l->label()])->all();
@endphp

<x-page-header title="Tambah Role" icon="shield-check"
               back="{{ route('roles.index') }}" backLabel="Kembali ke daftar role"
               subtitle="Buat role baru lalu tentukan hak akses per modul." />

<x-card class="max-w-3xl">
    <form method="POST" action="{{ route('roles.store') }}" class="space-y-4">
        @csrf
        @include('roles._form', ['role' => $role, 'levels' => $levels, 'levelOptions' => $levelOptions])
        <div class="flex gap-2 pt-2">
            <x-btn type="submit">Simpan</x-btn>
            <x-btn :href="route('roles.index')" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>
@endsection
