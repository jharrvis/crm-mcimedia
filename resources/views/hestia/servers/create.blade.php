@extends('layouts.app')

@section('title', 'Tambah Server Hestia')

@section('content')
<x-page-header title="Tambah Server HestiaCP" icon="server"
               back="{{ route('hestia.servers.index') }}" backLabel="Kembali ke daftar server"
               subtitle="Server bisa disimpan lebih dulu tanpa kredensial, lalu dilengkapi nanti. Sinkron hanya berjalan bila host dan salah satu metode autentikasi terisi." />

<x-card class="mb-6 max-w-3xl">
    <form method="POST" action="{{ route('hestia.servers.store') }}">
        @csrf
        @include('hestia.servers._form', ['server' => null])

        <div class="mt-6 flex items-center gap-2">
            <x-btn type="submit" icon="plus">Simpan server</x-btn>
            <x-btn :href="route('hestia.servers.index')" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>
@endsection
