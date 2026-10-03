{{--
    Formulir server HestiaCP (F4-12) — dipakai bersama oleh create & edit.

    PENTING (keamanan): field rahasia SELALU dirender sebagai input kosong dengan
    placeholder "•••••• (tersimpan)". Nilai kredensial terenkripsi tidak pernah
    dikirim ke browser. Bila admin tidak mengisi field rahasia saat edit, nilai
    lama dipertahankan (lihat HestiaServerRequest::mergedCredentials()).
--}}
@php
    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    $hasStoredSecrets = $server->exists && ! empty(array_filter($server->credentialBag(), fn ($v) => (string) $v !== ''));
    $secretPlaceholder = $hasStoredSecrets ? '•••••• (tersimpan — kosongkan untuk mempertahankan)' : '';
@endphp

<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="name" class="mb-1 block text-sm font-medium">Nama server</label>
            <input id="name" name="name" value="{{ old('name', $server->name) }}" required
                   placeholder="mis. sg2, YIARI, PA Salatiga" class="{{ $inputClass }}">
            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-slate-500">Label untuk ditampilkan di daftar &amp; laporan.</p>
        </div>

        <div>
            <label for="code" class="mb-1 block text-sm font-medium">Kode server</label>
            <input id="code" name="code" value="{{ old('code', $server->code) }}"
                   placeholder="dibuat otomatis dari nama" class="{{ $inputClass }}">
            @error('code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-slate-500">
                Pengenal stabil (huruf kecil/angka/hubung) yang masuk ke kunci akun
                <code>srv:&lt;kode&gt;:dom:&lt;user&gt;:&lt;domain&gt;</code>. Mengubah kode setelah sinkron
                membuat akun lama tersinkron ulang sebagai akun baru.
            </p>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-3">
            <label for="host" class="mb-1 block text-sm font-medium">Host</label>
            <input id="host" name="host" value="{{ old('host', $server->host) }}" required
                   placeholder="sg2.contoh.com" class="{{ $inputClass }}">
            @error('host') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="scheme" class="mb-1 block text-sm font-medium">Skema</label>
            <select id="scheme" name="scheme" class="{{ $inputClass }}">
                <option value="https" @selected(old('scheme', $server->scheme) === 'https')>https</option>
                <option value="http" @selected(old('scheme', $server->scheme) === 'http')>http</option>
            </select>
            @error('scheme') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="port" class="mb-1 block text-sm font-medium">Port</label>
            <input id="port" name="port" type="number" min="1" max="65535"
                   value="{{ old('port', $server->port ?: 8083) }}" class="{{ $inputClass }}">
            @error('port') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="timeout" class="mb-1 block text-sm font-medium">Timeout (detik)</label>
            <input id="timeout" name="timeout" type="number" min="1" max="300"
                   value="{{ old('timeout', $server->timeout ?: 30) }}" class="{{ $inputClass }}">
            @error('timeout') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="netdata_host" class="mb-1 block text-sm font-medium">Netdata Host</label>
            <input id="netdata_host" name="netdata_host" value="{{ old('netdata_host', $server->netdata_host) }}"
                   placeholder="kosongkan jika sama dengan host panel" class="{{ $inputClass }}">
            @error('netdata_host') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-slate-500">Host Netdata (biasanya sama IP dengan panel, beda port).</p>
        </div>

        <div>
            <label for="netdata_port" class="mb-1 block text-sm font-medium">Netdata Port</label>
            <input id="netdata_port" name="netdata_port" type="number" min="1" max="65535"
                   value="{{ old('netdata_port', $server->netdata_port ?: 19999) }}" class="{{ $inputClass }}">
            @error('netdata_port') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-slate-500">Port Netdata (default 19999).</p>
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="verify_ssl" value="1"
               @checked(old('verify_ssl', $server->verify_ssl))>
        <span>Verifikasi sertifikat SSL</span>
    </label>
    <p class="-mt-4 text-xs text-slate-500">
        API HestiaCP umumnya memakai sertifikat self-signed, jadi biarkan nonaktif
        bila tidak diperlukan.
    </p>

    {{-- Kredensial (disimpan terenkripsi) --}}
    <fieldset class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
        <legend class="px-1 text-sm font-semibold">Kredensial API</legend>
        <p class="mb-3 text-xs text-slate-500">
            Disimpan terenkripsi di database dan tidak pernah ditampilkan kembali.
            Pilih salah satu metode: access/secret key (disarankan, Hestia &ge; 1.6)
            atau user/password admin.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="credentials_access_key" class="mb-1 block text-sm font-medium">Access key</label>
                <input id="credentials_access_key" name="credentials[access_key]" type="password"
                       value="" autocomplete="new-password" placeholder="{{ $secretPlaceholder }}"
                       class="{{ $inputClass }}">
                @error('credentials.access_key') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="credentials_secret_key" class="mb-1 block text-sm font-medium">Secret key</label>
                <input id="credentials_secret_key" name="credentials[secret_key]" type="password"
                       value="" autocomplete="new-password" placeholder="{{ $secretPlaceholder }}"
                       class="{{ $inputClass }}">
                @error('credentials.secret_key') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="credentials_user" class="mb-1 block text-sm font-medium">User</label>
                <input id="credentials_user" name="credentials[user]" type="password"
                       value="" autocomplete="new-password" placeholder="{{ $secretPlaceholder }}"
                       class="{{ $inputClass }}">
                @error('credentials.user') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="credentials_password" class="mb-1 block text-sm font-medium">Password</label>
                <input id="credentials_password" name="credentials[password]" type="password"
                       value="" autocomplete="new-password" placeholder="{{ $secretPlaceholder }}"
                       class="{{ $inputClass }}">
                @error('credentials.password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </fieldset>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="notes" class="mb-1 block text-sm font-medium">Catatan</label>
            <input id="notes" name="notes" value="{{ old('notes', $server->notes) }}"
                   placeholder="mis. panel sg2 — akun MCI Media" class="{{ $inputClass }}">
            @error('notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 self-end pb-2 text-sm">
            <input type="checkbox" name="is_active" value="1"
                   @checked(old('is_active', $server->exists ? $server->is_active : true))>
            <span>Aktif (ikut sync terjadwal)</span>
        </label>
    </div>
</div>