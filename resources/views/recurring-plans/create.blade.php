@extends('layouts.app')

@section('title', 'Buat Paket Recurring')

@section('content')
<x-page-header title="Buat Paket Recurring" icon="repeat"
               back="{{ route('recurring-plans.index') }}" backLabel="Kembali ke daftar paket"
               subtitle="Invoice terbit otomatis sesuai siklus (bulanan / 3 / 6 / 12 bulan) tanpa perlu dibuat manual tiap periode." />

<x-card class="max-w-4xl">
    <form method="POST" action="{{ route('recurring-plans.store') }}" class="space-y-4">
        @csrf
        @include('recurring-plans._form', ['plan' => null, 'products' => $products])
        <div class="flex gap-2 pt-2">
            <x-btn type="submit" icon="plus">Simpan paket</x-btn>
            <x-btn :href="route('recurring-plans.index')" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>
@endsection
