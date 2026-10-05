@extends('layouts.app')

@section('title', 'Ubah Kata Sandi')

@section('content')
<x-page-header title="Ubah Kata Sandi" back="{{ route('dashboard') }}" backLabel="Kembali ke dashboard" icon="lock-keyhole" />

<div class="max-w-md">
    <x-card>
        <form method="POST" action="{{ route('profile.password.update') }}" class="space-y-1">
            @csrf
            @method('PUT')
            <x-input name="current_password" label="Kata sandi saat ini" type="password" :required="true" />
            <x-input name="password" label="Kata sandi baru" type="password" :required="true" hint="Minimal 8 karakter." />
            <x-input name="password_confirmation" label="Konfirmasi kata sandi baru" type="password" :required="true" />
            <x-btn type="submit">Simpan</x-btn>
        </form>
    </x-card>
</div>
@endsection
