@extends('layouts.app')

@section('title', 'Ubah Server Hestia')

@section('content')
<x-page-header :title="'Ubah Server ' . $server->name" icon="server"
               back="{{ route('hestia.servers.index') }}" backLabel="Kembali ke daftar server"
               subtitle="Field kredensial yang dikosongkan akan mempertahankan nilai lama yang sudah tersimpan." />

<x-card class="mb-6 max-w-3xl">
    <form method="POST" action="{{ route('hestia.servers.update', $server) }}">
        @csrf
        @method('PUT')
        @include('hestia.servers._form', ['server' => $server])

        <div class="mt-6 flex items-center gap-2">
            <x-btn type="submit">Simpan perubahan</x-btn>
            <x-btn :href="route('hestia.servers.index')" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>
@endsection
