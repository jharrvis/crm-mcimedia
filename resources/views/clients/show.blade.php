@extends('layouts.app')

@section('title', $client->name)

@section('content')
<x-page-header :title="$client->name" back="{{ route('clients.index') }}" backLabel="Kembali ke daftar" icon="users">
    <x-btn :href="route('clients.edit', $client)" variant="outline">Ubah</x-btn>
    <form method="POST" action="{{ route('clients.destroy', $client) }}"
          onsubmit="return confirm('Hapus klien ini beserta layanannya?')">
        @csrf
        @method('DELETE')
        <x-btn type="submit" variant="danger">Hapus</x-btn>
    </form>
</x-page-header>

<div class="grid gap-6 lg:grid-cols-3">
    <x-card>
        <x-slot:header>
            <h2 class="font-bold">Info klien</h2>
        </x-slot:header>
        <dl class="space-y-2 text-sm">
            <div><dt class="text-xs uppercase text-slate-400">Nama usaha</dt><dd class="font-medium">{{ $client->name }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Kontak utama</dt><dd>{{ $client->contact_name ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Email</dt><dd>{{ $client->email ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">WhatsApp</dt><dd>{{ $client->whatsapp ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Alamat</dt><dd>{{ $client->address ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Catatan</dt><dd>{{ $client->notes ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Status</dt>
                <dd>
                    <x-badge :variant="$client->is_active ? 'success' : 'slate'" :dot="true">
                        {{ $client->is_active ? 'Aktif' : 'Arsip' }}
                    </x-badge>
                </dd>
            </div>
        </dl>
    </x-card>

    <div class="space-y-6 lg:col-span-2">
        {{-- Pembungkus modal: `open` mulai true bila validasi gagal agar error tidak hilang. --}}
        <div x-data="{ open: @js($errors->hasAny(['name', 'role', 'email', 'whatsapp'])) }" @keydown.escape.window="open = false">
        <x-card>
            <x-slot:header>
                <h2 class="font-bold">Kontak ({{ $client->contacts->count() }})</h2>
                <button type="button" @click="open = true"
                        class="ml-auto text-sm font-semibold text-brand-600 hover:underline">Tambah kontak</button>
            </x-slot:header>

            <x-table>
                <thead><tr>
                    <th>Nama</th><th>Peran</th><th>Email</th><th>WA</th><th class="text-right">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($client->contacts as $contact)
                        <tr>
                            <td class="font-medium">{{ $contact->name }}</td>
                            <td>{{ $contact->role ?? '—' }}</td>
                            <td>{{ $contact->email ?? '—' }}</td>
                            <td>{{ $contact->whatsapp ?? '—' }}</td>
                            <td class="text-right">
                                <a href="{{ route('clients.contacts.edit', [$client, $contact]) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                                <form method="POST" action="{{ route('clients.contacts.destroy', [$client, $contact]) }}" class="inline"
                                      onsubmit="return confirm('Hapus kontak ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ml-2 text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">
                            <x-empty-state title="Belum ada kontak tambahan" icon="users" />
                        </td></tr>
                    @endforelse
                </tbody>
            </x-table>
        </x-card>

        {{-- Modal tambah kontak: POST biasa ke clients.contacts.store (pola form halaman lain). --}}
        <div x-show="open" x-cloak x-transition.opacity.duration.150ms
             class="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4"
             @click.self="open = false" role="dialog" aria-modal="true" aria-labelledby="contact-modal-title">
            <div x-transition.duration.150ms
                 class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-800 dark:bg-slate-900">
                <div class="mb-4 flex items-center gap-3">
                    <h3 id="contact-modal-title" class="font-bold">Tambah kontak</h3>
                    <p class="text-sm text-slate-400">Klien: {{ $client->name }}</p>
                    <button type="button" @click="open = false" aria-label="Tutup"
                            class="ml-auto grid h-8 w-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800">
                        <i data-lucide="x" class="h-4 w-4"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('clients.contacts.store', $client) }}" class="space-y-1">
                    @csrf
                    @include('clients.contacts._form', ['contact' => null])

                    <div class="flex gap-2 pt-2">
                        <x-btn type="submit">Simpan</x-btn>
                        <x-btn type="button" @click="open = false" variant="outline">Batal</x-btn>
                    </div>
                </form>
            </div>
        </div>
        </div><!-- /x-data modal -->

        <x-card>
            <x-slot:header>
                <h2 class="font-bold">Layanan ({{ $client->services->count() }})</h2>
            </x-slot:header>

            <x-table>
                <thead><tr>
                    <th>Layanan</th><th>Jenis</th><th>Berakhir</th><th class="text-right">Harga</th>
                </tr></thead>
                <tbody>
                    @forelse ($client->services as $service)
                        @php $isSub = $service->isChild(); @endphp
                        <tr @class(['bg-slate-50/60 dark:bg-slate-950/30' => $isSub])>
                            <td @class(['pl-8' => $isSub])>
                                @if ($isSub)<span class="mr-1 text-slate-400" aria-hidden="true">↳</span>@endif
                                <a href="{{ route('services.show', $service) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{{ $service->name }}</a>
                                @if ($service->parent)<p class="text-xs text-slate-400">dari {{ $service->parent->name }}</p>@endif
                            </td>
                            <td>{{ $service->type->label() }}</td>
                            <td>{{ tgl_id($service->end_date) }}</td>
                            <td class="text-right">{{ rupiah($service->price) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">
                            <x-empty-state title="Belum ada layanan" icon="wrench" />
                        </td></tr>
                    @endforelse
                </tbody>
            </x-table>
        </x-card>

        <x-card>
            <x-slot:header>
                <h2 class="font-bold">Project ({{ $client->projects->count() }})</h2>
            </x-slot:header>

            <x-table>
                <thead><tr>
                    <th>Judul</th><th>Status</th><th>Deadline</th>
                </tr></thead>
                <tbody>
                    @forelse ($client->projects as $project)
                        <tr>
                            <td><a href="{{ route('projects.show', $project) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{{ $project->title }}</a></td>
                            <td><x-badge :variant="$project->status->value === 'ongoing' ? 'info' : 'slate'" :dot="true">{{ $project->status->label() }}</x-badge></td>
                            <td>{{ tgl_id($project->deadline) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">
                            <x-empty-state title="Belum ada project" icon="folder" />
                        </td></tr>
                    @endforelse
                </tbody>
            </x-table>
        </x-card>

        <x-card>
            <x-slot:header>
                <h2 class="font-bold">Tugas ({{ $client->tasks->count() }})</h2>
            </x-slot:header>

            @if ($client->tasks->isEmpty())
                <x-empty-state title="Belum ada tugas" icon="list-checks" />
            @else
                <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    @foreach ($client->tasks as $task)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="{{ $task->status->value === 'done' ? 'text-slate-400 line-through' : 'font-medium' }}">{{ $task->title }}</span>
                            <span class="shrink-0 text-xs text-slate-400">{{ $task->status->label() }} · {{ tgl_id($task->due_date) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</div>
@endsection
