@extends('layouts.app')

@section('title', 'Ubah Paket Recurring')

@section('content')
<x-page-header title="Ubah Paket Recurring" :subtitle="$plan->title" icon="repeat"
               back="{{ route('recurring-plans.show', $plan) }}" backLabel="Kembali ke detail paket" />

<x-card class="max-w-4xl">
    <form method="POST" action="{{ route('recurring-plans.update', $plan) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('recurring-plans._form', ['plan' => $plan, 'products' => $products])

        <p class="text-xs text-slate-400">
            Perubahan item berlaku untuk periode berikutnya. Invoice yang sudah terbit tidak ikut berubah
            (dokumen keuangan bersifat final).
        </p>

        <div class="flex gap-2 pt-2">
            <x-btn type="submit">Simpan perubahan</x-btn>
            <x-btn :href="route('recurring-plans.show', $plan)" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>
@endsection
