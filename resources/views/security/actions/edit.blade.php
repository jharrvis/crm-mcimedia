@extends('layouts.app')

@section('title', 'Ubah Tindakan Keamanan')

@section('content')
<x-page-header title="Ubah Tindakan Keamanan" icon="history"
               back="{{ route('security.actions.index') }}" backLabel="Kembali ke jurnal" />

<x-card class="max-w-3xl">
    <form method="POST" action="{{ route('security.actions.update', $action) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('security.actions._form')
        <div class="flex gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
            <x-btn type="submit">Simpan perubahan</x-btn>
            <x-btn :href="route('security.actions.index')" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>
@endsection