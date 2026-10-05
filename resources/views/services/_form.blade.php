@php
    $typeValue = old('type', $service?->type?->value);
    $cycleValue = old('cycle', $service?->cycle?->value ?? 'yearly');
    $statusValue = old('status', $service?->status?->value ?? 'active');
    // F4-9: domain induk (hanya untuk jenis domain). Nilai yang sudah
    // terisi (tebakan otomatis) tetap terpilih kecuali admin memilih lain.
    $parentCandidates = $parentCandidates ?? collect();
    $parentValue = old('parent_id', $service?->parent_id ?? ($suggestedParentId ?? null));
    $clientOptions = $clients->mapWithKeys(fn ($c) => [$c->id => $c->name])->all();
    $typeOptions = collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all();
    $cycleOptions = collect($cycles)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $statusOptions = collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    // data-client dipakai script di bawah untuk menyaring kandidat sesuai klien terpilih.
    $parentList = $parentCandidates->map(fn ($p) => [
        'value' => $p->id,
        'label' => $p->name . ($p->reference ? " ({$p->reference})" : ''),
        'attrs' => ['data-client' => $p->client_id],
    ])->values()->all();
    // array_merge, bukan `+`: union memakai key dan menabrak key 0 placeholder.
    $parentOptions = array_merge([['value' => '', 'label' => '— Bukan subdomain —']], $parentList);
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <x-input name="client_id" label="Klien" type="select" :required="true" :options="$clientOptions" :value="old('client_id', $service?->client_id)" placeholder="" />
    <x-input name="type" label="Jenis" type="select" :required="true" :options="$typeOptions" :value="$typeValue" />
    <div class="sm:col-span-2">
        <x-input name="name" label="Nama layanan" :required="true" :value="$service?->name" placeholder="mis. Hosting Bisnis" />
    </div>
    <div class="sm:col-span-2" data-parent-field>
        <x-input name="parent_id" label="Domain induk (subdomain)" type="select" :options="$parentOptions" :value="(string) $parentValue" inputId="service-parent" hint="Pilih domain induk bila layanan ini subdomain, agar tampil terkelompok di bawah domain induknya. Hanya untuk jenis Domain dan harus milik klien yang sama." />
    </div>
    <x-input name="reference" label="Domain / server terkait" :value="$service?->reference" placeholder="mis. contoh.com" />
    <x-input name="price" label="Harga (IDR)" type="number" :value="old('price', $service?->price ?? 0)" min="0" step="1" />
    <x-input name="start_date" label="Tanggal mulai" type="date" :value="old('start_date', $service?->start_date?->format('Y-m-d'))" />
    <x-input name="end_date" label="Tanggal berakhir" type="date" :value="old('end_date', $service?->end_date?->format('Y-m-d'))" />
    <x-input name="cycle" label="Siklus" type="select" :required="true" :options="$cycleOptions" :value="$cycleValue" />
    <x-input name="status" label="Status" type="select" :required="true" :options="$statusOptions" :value="$statusValue" />
    <div class="sm:col-span-2">
        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
            <input name="reminder_enabled" type="checkbox" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('reminder_enabled', $service?->reminder_enabled ?? true))>
            Pengingat jatuh tempo otomatis aktif
        </label>
    </div>
    <div class="sm:col-span-2">
        <x-input name="notes" label="Catatan" type="textarea" rows="3" :value="$service?->notes" />
    </div>
</div>

<script>
    // F4-9: field "Domain induk" hanya relevan untuk jenis Domain, dan
    // kandidat domain induk disaring sesuai klien terpilih (sumber kebenaran
    // tetap validasi server di ServiceRequest).
    (() => {
        const clientSelect = document.querySelector('select[name="client_id"]');
        const typeSelect = document.querySelector('select[name="type"]');
        const parentField = document.querySelector('[data-parent-field]');
        const parentSelect = document.getElementById('service-parent');

        if (! parentField || ! parentSelect) return;

        function isDomainType() {
            return typeSelect?.value === 'domain';
        }

        function syncParentField() {
            parentField.classList.toggle('hidden', ! isDomainType());

            if (! isDomainType() && parentSelect.value) {
                parentSelect.value = '';
            }
        }

        function filterParents() {
            const clientId = clientSelect?.value || '';

            Array.from(parentSelect.options).forEach((opt) => {
                if (! opt.value) return;
                opt.hidden = Boolean(clientId) && opt.dataset.client !== clientId;
            });

            if (parentSelect.selectedOptions[0]?.hidden) {
                parentSelect.value = '';
            }
        }

        clientSelect?.addEventListener('change', filterParents);
        typeSelect?.addEventListener('change', syncParentField);

        filterParents();
        syncParentField();
    })();
</script>
