@props([
    'clients',
    'selected' => null,
    'name' => 'client_id',
    'id' => null,
    'label' => null,
    'labelClass' => 'mb-1 block text-sm font-medium',
    'emptyLabel' => '— Pilih klien —',
    'required' => false,
    'class' => 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800',
])

@php
    // Satu-satunya tempat di aplikasi yang memilih klien (UX-4). Input pencarian
    // menyaring opsi di sisi peramban; <select> tetap native agar pengiriman
    // form, validasi `required`, dan skrip dependent (filter layanan invoice,
    // domain induk pada layanan) tetap bekerja tanpa perubahan lain.
    $id = $id ?: 'client-select-'.substr(md5($name.'|'.($label ?? '').(string) $selected), 0, 8);
    $searchId = $id.'-search';
    $selectedKey = (string) ($selected ?? '');
@endphp

<div class="client-select" data-client-select>
    @if ($label)
        <label for="{{ $searchId }}" class="{{ $labelClass }}">
            {{ $label }} @if ($required)<span class="text-red-600">*</span>@endif
        </label>
    @endif

    <input type="search" id="{{ $searchId }}" data-client-search autocomplete="off"
           aria-label="Cari klien"
           placeholder="Cari nama usaha atau kontak…"
           class="mb-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800">

    <select id="{{ $id }}" name="{{ $name }}" @required($required) class="{{ $class }}">
        <option value="">{{ $emptyLabel }}</option>
        @foreach ($clients as $client)
            @php
                // Kontak & status hanya ditampilkan bila kolomnya benar-benar
                // di-select controller; kalau tidak, akses property akan
                // memicu query per klien (N+1).
                $contact = $client->getAttributes()['contact_name'] ?? null;
                $inactive = array_key_exists('is_active', $client->getAttributes()) && ! $client->is_active;
            @endphp
            <option value="{{ $client->id }}" data-contact="{{ $contact }}" @selected($selectedKey !== '' && $selectedKey === (string) $client->id)>{{ $client->name }}@if ($inactive) (nonaktif)@endif</option>
        @endforeach
    </select>

    <p data-client-count class="mt-1 text-xs text-slate-500" aria-live="polite"></p>

    @error($name)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>