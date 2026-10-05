@extends('layouts.app')

@section('title', 'Tambah Tugas')

@section('content')
<x-page-header title="Tambah Tugas" back="{{ route('tasks.index') }}" backLabel="Kembali ke daftar" icon="list-checks" />

<div class="max-w-2xl">
    <x-card>
        <form method="POST" action="{{ route('tasks.store') }}" class="space-y-4">
            @csrf
            @include('tasks._form', ['task' => null])

            <div class="flex gap-2 pt-2">
                <x-btn type="submit">Tambah tugas</x-btn>
                <x-btn :href="route('tasks.index')" variant="outline">Batal</x-btn>
            </div>
        </form>
    </x-card>
</div>
@endsection
