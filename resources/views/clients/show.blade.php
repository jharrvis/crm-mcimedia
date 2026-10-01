@extends('layouts.app')

@section('title', $client->name)

@section('content')
<div class="mb-4 flex items-center justify-between">
    <a href="{{ route('clients.index') }}" class="text-sm text-slate-500 hover:underline">← Kembali ke daftar</a>
    <div class="flex gap-2">
        <a href="{{ route('clients.edit', $client) }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Ubah</a>
        <form method="POST" action="{{ route('clients.destroy', $client) }}"
              onsubmit="return confirm('Hapus klien ini beserta layanannya?')">
            @csrf
            @method('DELETE')
            <button class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Hapus</button>
        </form>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="mb-3 font-bold">Info klien</h2>
        <dl class="space-y-2 text-sm">
            <div><dt class="text-xs uppercase text-slate-500">Nama usaha</dt><dd class="font-medium">{{ $client->name }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-500">Kontak utama</dt><dd>{{ $client->contact_name ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-500">Email</dt><dd>{{ $client->email ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-500">WhatsApp</dt><dd>{{ $client->whatsapp ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-500">Alamat</dt><dd>{{ $client->address ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-500">Catatan</dt><dd>{{ $client->notes ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-500">Status</dt>
                <dd>
                    @if ($client->is_active)
                        <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">Aktif</span>
                    @else
                        <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">Arsip</span>
                    @endif
                </dd>
            </div>
        </dl>
    </div>

    <div class="lg:col-span-2 space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-bold">Kontak ({{ $client->contacts->count() }})</h2>
                <a href="{{ route('clients.contacts.create', $client) }}"
                   class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Tambah kontak</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase text-slate-500">
                        <th class="py-2 pr-4">Nama</th><th class="py-2 pr-4">Peran</th><th class="py-2 pr-4">Email</th><th class="py-2 pr-4">WA</th><th class="py-2 text-right">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($client->contacts as $contact)
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="py-2 pr-4 font-medium">{{ $contact->name }}</td>
                                <td class="py-2 pr-4">{{ $contact->role ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $contact->email ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $contact->whatsapp ?? '—' }}</td>
                                <td class="py-2 text-right">
                                    <a href="{{ route('clients.contacts.edit', [$client, $contact]) }}" class="text-indigo-600 hover:underline">Ubah</a>
                                    <form method="POST" action="{{ route('clients.contacts.destroy', [$client, $contact]) }}" class="inline"
                                          onsubmit="return confirm('Hapus kontak ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="ml-2 text-red-600 hover:underline">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-4 text-center text-slate-500">Belum ada kontak tambahan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-3 font-bold">Layanan ({{ $client->services->count() }})</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase text-slate-500">
                        <th class="py-2 pr-4">Layanan</th><th class="py-2 pr-4">Jenis</th><th class="py-2 pr-4">Berakhir</th><th class="py-2 text-right">Harga</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($client->services as $service)
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="py-2 pr-4"><a href="{{ route('services.show', $service) }}" class="font-medium text-indigo-600 hover:underline">{{ $service->name }}</a></td>
                                <td class="py-2 pr-4">{{ $service->type->label() }}</td>
                                <td class="py-2 pr-4">{{ tgl_id($service->end_date) }}</td>
                                <td class="py-2 text-right">{{ rupiah($service->price) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-center text-slate-500">Belum ada layanan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-3 font-bold">Project ({{ $client->projects->count() }})</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase text-slate-500">
                        <th class="py-2 pr-4">Judul</th><th class="py-2 pr-4">Status</th><th class="py-2">Deadline</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($client->projects as $project)
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="py-2 pr-4"><a href="{{ route('projects.show', $project) }}" class="font-medium text-indigo-600 hover:underline">{{ $project->title }}</a></td>
                                <td class="py-2 pr-4">{{ $project->status->label() }}</td>
                                <td class="py-2">{{ tgl_id($project->deadline) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-4 text-center text-slate-500">Belum ada project.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-3 font-bold">Tugas ({{ $client->tasks->count() }})</h2>
            <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                @forelse ($client->tasks as $task)
                    <li class="flex items-center justify-between py-2">
                        <span class="{{ $task->status->value === 'done' ? 'text-slate-400 line-through' : 'font-medium' }}">{{ $task->title }}</span>
                        <span class="text-xs text-slate-500">{{ $task->status->label() }} · {{ tgl_id($task->due_date) }}</span>
                    </li>
                @empty
                    <li class="py-4 text-center text-slate-500">Belum ada tugas.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
