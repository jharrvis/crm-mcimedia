@extends('layouts.app')

@section('title', 'Ubah Tugas')

@section('content')
<x-page-header title="Ubah Tugas" back="{{ route('tasks.show', $task) }}" backLabel="Kembali ke detail" icon="list-checks" />

<div class="max-w-2xl">
    <x-card>
        <form method="POST" action="{{ route('tasks.update', $task) }}" class="space-y-4">
            @csrf
            @method('PUT')
            @include('tasks._form', ['task' => $task])

            <div class="flex gap-2 pt-2">
                <x-btn type="submit">Simpan perubahan</x-btn>
                <x-btn :href="route('tasks.show', $task)" variant="outline">Batal</x-btn>
            </div>
        </form>
    </x-card>
</div>
@endsection
