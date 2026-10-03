@php
    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    $typeValue = old('type', $service?->type?->value);
    $cycleValue = old('cycle', $service?->cycle?->value ?? 'yearly');
    $statusValue = old('status', $service?->status?->value ?? 'active');
    // F4-9: domain induk (hanya untuk jenis domain). Nilai yang sudah
    // terisi (tebakan otomatis) tetap terpilih kecuali admin memilih lain.
    $parentCandidates = $parentCandidates ?? collect();
    $parentValue = old('parent_id', $service?->parent_id ?? ($suggestedParentId ?? null));
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <x-client-select
            :clients="$clients"
            :selected="old('client_id', $service?->client_id)"
            label="Klien"
            required />
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Jenis <span class="text-red-600">*</span></label>
        <select name="type" required class="{{ $inputClass }}">
            @foreach ($types as $t)
                <option value="{{ $t->value }}" @selected($typeValue === $t->value)>{{ $t->label() }}</option>
            @endforeach
        </select>
        @error('type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Nama layanan <span class="text-red-600">*</span></label>
        <input name="name" required value="{{ old('name', $service?->name) }}" placeholder="mis. Hosting Bisnis" class="{{ $inputClass }}">
        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2" data-parent-field>
        <label class="mb-1 block text-sm font-medium">Domain induk (subdomain)</label>
        <select name="parent_id" id="service-parent" class="{{ $inputClass }}">
            <option value="">— Bukan subdomain —</option>
            @foreach ($parentCandidates as $parent)
                <option value="{{ $parent->id }}" data-client="{{ $parent->client_id }}" @selected((string) $parentValue === (string) $parent->id)>
                    {{ $parent->name }}@if ($parent->reference) ({{ $parent->reference }})@endif
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">
            Pilih domain induk bila layanan ini subdomain, agar tampil terkelompok di bawah domain induknya.
            Hanya untuk jenis <strong>Domain</strong> dan harus milik klien yang sama.
        </p>
        @error('parent_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Domain / server terkait</label>
        <input name="reference" value="{{ old('reference', $service?->reference) }}" placeholder="mis. contoh.com" class="{{ $inputClass }}">
        @error('reference')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Harga (IDR)</label>
        <input name="price" type="number" min="0" step="1" value="{{ old('price', $service?->price ?? 0) }}" class="{{ $inputClass }}">
        @error('price')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Tanggal mulai</label>
        <input name="start_date" type="date" value="{{ old('start_date', $service?->start_date?->format('Y-m-d')) }}" class="{{ $inputClass }}">
        @error('start_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Tanggal berakhir</label>
        <input name="end_date" type="date" value="{{ old('end_date', $service?->end_date?->format('Y-m-d')) }}" class="{{ $inputClass }}">
        @error('end_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Siklus <span class="text-red-600">*</span></label>
        <select name="cycle" required class="{{ $inputClass }}">
            @foreach ($cycles as $c)
                <option value="{{ $c->value }}" @selected($cycleValue === $c->value)>{{ $c->label() }}</option>
            @endforeach
        </select>
        @error('cycle')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Status <span class="text-red-600">*</span></label>
        <select name="status" required class="{{ $inputClass }}">
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}" @selected($statusValue === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        @error('status')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="flex items-center gap-2 text-sm">
            <input name="reminder_enabled" type="checkbox" value="1" class="rounded" @checked(old('reminder_enabled', $service?->reminder_enabled ?? true))>
            Pengingat jatuh tempo otomatis aktif
        </label>
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Catatan</label>
        <textarea name="notes" rows="3" class="{{ $inputClass }}">{{ old('notes', $service?->notes) }}</textarea>
        @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
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
