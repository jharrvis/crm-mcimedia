@extends('layouts.public')

@section('title', 'Invoice '.$invoice->number.' dibatalkan')

@section('content')
<div class="rounded-xl border border-amber-200 bg-white p-8 text-center dark:border-amber-900 dark:bg-slate-900">
    <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-2xl font-bold text-amber-700">✕</div>
    <h1 class="text-xl font-bold">Invoice dibatalkan</h1>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
        Invoice <strong>{{ $invoice->number }}</strong> telah dibatalkan dan tidak perlu dibayar.
    </p>
    <p class="mt-1 text-sm text-slate-500">
        Jika Anda merasa ini keliru, silakan hubungi {{ $business['name'] }} di {{ $business['email'] }}.
    </p>
</div>
@endsection
