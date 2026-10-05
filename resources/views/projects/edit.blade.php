@extends('layouts.app')

@section('title', 'Ubah Project')

@section('content')
<x-page-header title="Ubah Project" back="{{ route('projects.show', $project) }}" backLabel="Kembali ke detail" icon="folder-kanban" />

<div class="max-w-2xl">
    <x-card>
        <form method="POST" action="{{ route('projects.update', $project) }}" class="space-y-4">
            @csrf
            @method('PUT')
            @include('projects._form', ['project' => $project])

            <div class="flex gap-2 pt-2">
                <x-btn type="submit">Simpan perubahan</x-btn>
                <x-btn :href="route('projects.show', $project)" variant="outline">Batal</x-btn>
            </div>
        </form>
    </x-card>
</div>
@endsection
