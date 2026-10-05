@extends('layouts.app')

@section('title', 'Unggah Laporan Keamanan')

@section('content')
<x-page-header title="Unggah Laporan Keamanan" icon="file-text"
               back="{{ route('security.reports.index') }}" backLabel="Kembali ke daftar laporan" />

<x-card class="max-w-2xl">
    <form method="POST" action="{{ route('security.reports.store') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <x-input name="client_id" label="Klien" type="select" :required="true"
                 :options="['' => '— Pilih klien —'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all()"
                 :value="(int) old('client_id', $defaultClientId)" />
        <x-input name="period" label="Periode (YYYY-MM)" :required="true" :value="old('period', $defaultPeriod)"
                 placeholder="2026-09" pattern="\d{4}-(0[1-9]|1[0-2])" />
        <div>
            <label for="file" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Berkas PDF <span class="text-red-600">*</span></label>
            <input type="file" name="file" id="file" accept="application/pdf" required
                   class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-700 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:file:bg-slate-700 dark:file:text-slate-200" />
            <p class="mt-1 text-xs text-slate-400">Hanya PDF, maksimum {{ config('crm.security.report_max_kb') }} KB. Berkas disimpan privat di storage aplikasi.</p>
            @error('file')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="flex gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
            <x-btn type="submit">Unggah</x-btn>
            <x-btn :href="route('security.reports.index')" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>
@endsection