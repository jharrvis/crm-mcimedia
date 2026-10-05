@extends('layouts.app')

@section('title', 'Tambah Pengguna')

@section('content')
<x-page-header title="Tambah Pengguna" icon="users"
               back="{{ route('users.index') }}" backLabel="Kembali ke daftar pengguna" />

<x-card class="max-w-2xl">
    <form method="POST" action="{{ route('users.store') }}" class="space-y-4">
        @csrf
        @include('users._form', ['user' => $user])
        <div class="flex gap-2 pt-2">
            <x-btn type="submit" icon="plus">Simpan</x-btn>
            <x-btn :href="route('users.index')" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>
@endsection
