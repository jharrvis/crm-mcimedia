{{--
    Form tambah/ubah provider domain (F4-5).
    Variabel: $action, $method, $driverKey, $drivers, $fields, $values, $provider (opsional).
--}}
@php
    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
@endphp

<form method="POST" action="{{ $action }}" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    @csrf
    @if (! empty($method))
        @method($method)
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="name" class="mb-1 block text-sm font-medium">Nama provider <span class="text-red-500">*</span></label>
            <input id="name" name="name" value="{{ old('name', $provider->name ?? '') }}" required class="{{ $inputClass }}">
            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="driver" class="mb-1 block text-sm font-medium">Driver <span class="text-red-500">*</span></label>
            <select id="driver" name="driver" class="{{ $inputClass }}"
                    onchange="window.location = '{{ url()->current() }}?driver=' + encodeURIComponent(this.value);">
                @foreach ($drivers as $key => $label)
                    <option value="{{ $key }}" @selected($driverKey === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @error('driver') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="notes" class="mb-1 block text-sm font-medium">Catatan</label>
        <input id="notes" name="notes" value="{{ old('notes', $provider->notes ?? '') }}" class="{{ $inputClass }}">
        @error('notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <label class="inline-flex items-center gap-2 text-sm">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 dark:border-slate-700"
               @checked((bool) old('is_active', $provider->is_active ?? true))>
        <span>Aktif</span>
    </label>

    <div class="border-t border-slate-100 pt-4 dark:border-slate-800">
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Kredensial</h3>
        <div class="space-y-4">
            @include('domain-providers._fields', ['fields' => $fields, 'values' => $values, 'provider' => $provider ?? null])
        </div>
    </div>

    <div class="flex items-center gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
        <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ $submitLabel ?? 'Simpan' }}</button>
        <a href="{{ route('domain-providers.index') }}" class="text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">Batal</a>
    </div>
</form>
