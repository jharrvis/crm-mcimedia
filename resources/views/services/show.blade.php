@extends('layouts.app')

@section('title', $service->name)

@section('content')
<div class="max-w-3xl rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <div class="mb-4 flex flex-wrap gap-2">
        <a href="{{ route('services.edit', $service) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Ubah</a>
        <form method="POST" action="{{ route('services.destroy', $service) }}" onsubmit="return confirm('Hapus layanan ini?')">
            @csrf
            @method('DELETE')
            <button class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-950">Hapus</button>
        </form>
        <a href="{{ route('services.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Kembali</a>
    </div>

    <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
        <div><dt class="text-slate-500">Klien</dt><dd class="font-medium"><a href="{{ route('clients.show', $service->client) }}" class="text-indigo-600 hover:underline">{{ $service->client?->name ?? '—' }}</a></dd></div>
        <div><dt class="text-slate-500">Jenis</dt><dd class="font-medium">{{ $service->type->label() }}</dd></div>
        <div><dt class="text-slate-500">Nama layanan</dt><dd class="font-medium">{{ $service->name }}</dd></div>
        <div><dt class="text-slate-500">Domain / server terkait</dt><dd class="font-medium">{{ $service->reference ?? '—' }}</dd></div>
        <div><dt class="text-slate-500">Tanggal mulai</dt><dd class="font-medium">{{ tgl_id($service->start_date) }}</dd></div>
        <div>
            <dt class="text-slate-500">Tanggal berakhir</dt>
            <dd class="font-medium">
                {{ tgl_id($service->end_date) }}
                @php $days = $service->daysUntilEnd(); @endphp
                @if ($service->isOverdue())
                    <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900 dark:text-red-200">{{ abs($days) }} hari lewat</span>
                @elseif ($days !== null && $days <= 30)
                    <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900 dark:text-amber-200">sisa {{ $days }} hari</span>
                @endif
            </dd>
        </div>
        <div><dt class="text-slate-500">Harga</dt><dd class="font-medium">{{ rupiah($service->price) }}</dd></div>
        <div><dt class="text-slate-500">Siklus</dt><dd class="font-medium">{{ $service->cycle->label() }}</dd></div>
        <div><dt class="text-slate-500">Status</dt><dd class="font-medium">{{ $service->status->label() }}</dd></div>
        <div><dt class="text-slate-500">Pengingat otomatis</dt><dd class="font-medium">{{ $service->reminder_enabled ? 'Aktif' : 'Nonaktif' }}</dd></div>
        <div class="sm:col-span-2"><dt class="text-slate-500">Catatan</dt><dd class="font-medium whitespace-pre-line">{{ $service->notes ?? '—' }}</dd></div>
    </dl>
</div>
@endsection
