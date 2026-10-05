{{--
    Form tambah/ubah provider domain (F4-5).
    Variabel: $action, $method, $driverKey, $drivers, $fields, $values, $provider (opsional).
--}}

<x-card>
    <x-slot:header>
        <h2 class="font-bold">Detail provider</h2>
    </x-slot:header>

    <form method="POST" action="{{ $action }}" class="space-y-4">
        @csrf
        @if (! empty($method))
            @method($method)
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <x-input name="name" label="Nama provider" :required="true" :value="$provider->name ?? ''" />

            <div>
                <x-input name="driver" label="Driver" type="select" :required="true" :options="$drivers" :value="$driverKey"
                         onchange="window.location = '{{ url()->current() }}?driver=' + encodeURIComponent(this.value);" />
            </div>
        </div>

        <x-input name="notes" label="Catatan" :value="$provider->notes ?? ''" />

        <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                   @checked((bool) old('is_active', $provider->is_active ?? true))>
            <span>Aktif</span>
        </label>

        <div class="border-t border-slate-100 pt-4 dark:border-slate-800">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-400">Kredensial</h3>
            <div class="space-y-4">
                @include('domain-providers._fields', ['fields' => $fields, 'values' => $values, 'provider' => $provider ?? null])
            </div>
        </div>

        <div class="flex items-center gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
            <x-btn type="submit">{{ $submitLabel ?? 'Simpan' }}</x-btn>
            <x-btn :href="route('domain-providers.index')" variant="outline">Batal</x-btn>
        </div>
    </form>
</x-card>