@extends('layouts.app')

@section('title', 'Tambah Project')

@section('content')
<x-page-header title="Tambah Project" back="{{ route('projects.index') }}" backLabel="Kembali ke daftar" icon="folder-kanban" />

<div class="max-w-2xl">
    <x-card>
        <form method="POST" action="{{ route('projects.store') }}" class="space-y-4">
            @csrf
            @include('projects._form', ['project' => null])

            <div class="flex gap-2 pt-2">
                <x-btn type="submit">Tambah project</x-btn>
                <x-btn :href="route('projects.index')" variant="outline">Batal</x-btn>
            </div>
        </form>
    </x-card>
</div>
@endsection
