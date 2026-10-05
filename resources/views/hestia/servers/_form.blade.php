{{--
    Formulir server HestiaCP (F4-12) — dipakai bersama oleh create & edit.

    PENTING (keamanan): field rahasia SELALU dirender sebagai input kosong dengan
    placeholder "•••••• (tersimpan)". Nilai kredensial terenkripsi tidak pernah
    dikirim ke browser. Bila admin tidak mengisi field rahasia saat edit, nilai
    lama dipertahankan (lihat HestiaServerRequest::mergedCredentials()).
--}}
@php
    $server = $server ?? null;
    $hasStoredSecrets = $server?->exists && ! empty(array_filter($server->credentialBag(), fn ($v) => (string) $v !== ''));
    $secretPlaceholder = $hasStoredSecrets ? '•••••• (tersimpan — kosongkan untuk mempertahankan)' : '';
@endphp

<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="name" label="Nama server" :required="true" :value="$server?->name"
                 placeholder="mis. sg2, YIARI, PA Salatiga"
                 hint="Label untuk ditampilkan di daftar &amp; laporan." />

        <x-input name="code" label="Kode server" :value="$server?->code"
                 placeholder="dibuat otomatis dari nama"
                 hint="Pengenal stabil (huruf kecil/angka/hubung) yang masuk ke kunci akun srv:&lt;kode&gt;:dom:&lt;user&gt;:&lt;domain&gt;. Mengubah kode setelah sinkron membuat akun lama tersinkron ulang sebagai akun baru." />
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-3">
            <x-input name="host" label="Host" :required="true" :value="$server?->host" placeholder="sg2.contoh.com" />
        </div>

        <x-input name="scheme" label="Skema" type="select" :options="['https' => 'https', 'http' => 'http']" :value="$server?->scheme" />
        <x-input name="port" label="Port" type="number" min="1" max="65535" :value="$server?->port ?: 8083" />
        <x-input name="timeout" label="Timeout (detik)" type="number" min="1" max="300" :value="$server?->timeout ?: 30" />

        <x-input name="netdata_host" label="Netdata Host" :value="$server?->netdata_host"
                 placeholder="kosongkan jika sama dengan host panel"
                 hint="Host Netdata (biasanya sama IP dengan panel, beda port)." />
        <x-input name="netdata_port" label="Netdata Port" type="number" min="1" max="65535" :value="$server?->netdata_port ?: 19999"
                 hint="Port Netdata (default 19999)." />
    </div>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="verify_ssl" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
               @checked(old('verify_ssl', $server?->verify_ssl))>
        <span>Verifikasi sertifikat SSL</span>
    </label>
    <p class="-mt-4 text-xs text-slate-400">
        API HestiaCP umumnya memakai sertifikat self-signed, jadi biarkan nonaktif
        bila tidak diperlukan.
    </p>

    {{-- Kredensial (disimpan terenkripsi) --}}
    <fieldset class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
        <legend class="px-1 text-sm font-semibold">Kredensial API</legend>
        <p class="mb-3 text-xs text-slate-400">
            Disimpan terenkripsi di database dan tidak pernah ditampilkan kembali.
            Pilih salah satu metode: access/secret key (disarankan, Hestia &ge; 1.6)
            atau user/password admin.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-input name="credentials[access_key]" label="Access key" type="password" value=""
                     autocomplete="new-password" :hint="$secretPlaceholder" class="mb-0" />
            <x-input name="credentials[secret_key]" label="Secret key" type="password" value=""
                     autocomplete="new-password" :hint="$secretPlaceholder" class="mb-0" />
            <x-input name="credentials[user]" label="User" type="password" value=""
                     autocomplete="new-password" :hint="$secretPlaceholder" class="mb-0" />
            <x-input name="credentials[password]" label="Password" type="password" value=""
                     autocomplete="new-password" :hint="$secretPlaceholder" class="mb-0" />
        </div>
    </fieldset>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="notes" label="Catatan" :value="$server?->notes"
                 placeholder="mis. panel sg2 — akun MCI Media" />

        <label class="flex items-center gap-2 self-end pb-2 text-sm">
            <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                   @checked(old('is_active', $server?->exists ? $server->is_active : true))>
            <span>Aktif (ikut sync terjadwal)</span>
        </label>
    </div>
</div>
