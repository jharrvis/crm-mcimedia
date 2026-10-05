@extends('layouts.app')

@section('title', 'Unggah Laporan Keamanan')

@section('content')
@php $input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800'; @endphp

<form method="POST" action="{{ route('security.reports.store') }}" enctype="multipart/form-data"
      class="max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    @csrf
    <div>
        <label class="mb-1 block text-sm font-medium">Klien <span class="text-red-600">*</span></label>
        <select name="client_id" required class="{{ $input }}">
            <option value="">— Pilih klien —</option>
            @foreach ($clients as $c)
                <option value="{{ $c->id }}" @selected((int) old('client_id', $defaultClientId) === $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        @error('client_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Periode (YYYY-MM) <span class="text-red-600">*</span></label>
        <input name="period" value="{{ old('period', $defaultPeriod) }}" placeholder="2026-09" pattern="\d{4}-(0[1-9]|1[0-2])" required class="{{ $input }}">
        @error('period')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Berkas PDF <span class="text-red-600">*</span></label>
        <input type="file" name="file" accept="application/pdf" required class="{{ $input }}">
        <p class="mt-1 text-xs text-slate-500">Hanya PDF, maksimum {{ config('crm.security.report_max_kb') }} KB. Berkas disimpan privat di storage aplikasi.</p>
        @error('file')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="flex gap-2 pt-2">
        <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Unggah</button>
        <a href="{{ route('security.reports.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
    </div>
</form>
@endsection
