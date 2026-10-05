@extends('layouts.app')

@section('title', 'Ubah Insiden Keamanan')

@section('content')
<x-page-header title="Ubah Insiden Keamanan" icon="shield-alert"
               back="{{ route('security.incidents.index') }}" backLabel="Kembali ke daftar insiden" />

<x-card class="max-w-3xl">
    <form method="POST" action="{{ route('security.incidents.update', $incident) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('security.incidents._form')
        <div class="flex gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
            <x-btn type="submit">Simpan perubahan</x-btn>
            <x-btn :href="route('security.incidents.index')" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>
@endsection