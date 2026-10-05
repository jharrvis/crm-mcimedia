{{-- Tombol kirim reminder manual (t_6f78ca1d). Sengaja satu partial supaya
     kedua tabel (overdue & expiring) tidak menduplikasi markup.
     `$canSend` = user punya level "kelola" modul Pengingat; tanpa itu tombol
     dirender disabled + judul alasannya supaya aksi tidak membingungkan. --}}
@php
    $canSend = auth()->user()?->hasPermission('reminders', 'manage') ?? false;
    $waMissing = blank($s->client?->whatsapp);
    $emailMissing = blank($s->client?->email);
    $fonnteOff = ! config('crm.fonnte.enabled') || blank(config('crm.fonnte.token'));
@endphp

@if ($canSend)
    <form method="POST" action="{{ route('reminders.send-whatsapp', $s) }}" class="inline"
          onsubmit="return confirm('Kirim reminder WhatsApp untuk {{ $s->name }}?')">
        @csrf
        <button class="text-sm font-semibold text-green-600 hover:underline disabled:cursor-not-allowed disabled:text-slate-400 disabled:no-underline"
                @disabled($waMissing || $fonnteOff)
                @if ($waMissing) title="Klien belum punya nomor WhatsApp"
                @elseif ($fonnteOff) title="Pengiriman WhatsApp belum dikonfigurasi (FONNTE_ENABLED/FONNTE_TOKEN)"
                @endif>Kirim WA</button>
    </form>
    <span class="mx-1 text-slate-300">|</span>
    <form method="POST" action="{{ route('reminders.send-email', $s) }}" class="inline"
          onsubmit="return confirm('Kirim reminder email untuk {{ $s->name }}?')">
        @csrf
        <button class="text-sm font-semibold text-brand-600 hover:underline disabled:cursor-not-allowed disabled:text-slate-400 disabled:no-underline"
                @disabled($emailMissing)
                title="{{ $emailMissing ? 'Klien belum punya alamat email' : '' }}">Kirim Email</button>
    </form>
@else
    <span class="text-xs text-slate-400" title="Butuh hak kelola modul Pengingat untuk mengirim reminder">Kelola untuk kirim</span>
@endif