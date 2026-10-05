@php
    $project = $project ?? null;
    $clientOptions = ['' => '— Pilih klien —'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all();
    $statusOptions = collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
@endphp
<div class="grid gap-4">
    <x-input name="client_id" label="Klien" type="select" :required="true" :options="$clientOptions"
             :value="(string) old('client_id', $project?->client_id)" placeholder="" />
    <x-input name="title" label="Judul project" :required="true" :value="$project?->title" />
    <x-input name="description" label="Deskripsi" type="textarea" rows="4" :value="$project?->description" />
    <div class="grid gap-4 sm:grid-cols-3">
        <x-input name="deadline" label="Deadline" type="date" :value="old('deadline', $project?->deadline?->format('Y-m-d'))" />
        <x-input name="status" label="Status" type="select" :required="true" :options="$statusOptions"
                 :value="(string) old('status', $project?->status?->value ?? 'new')" />
        <x-input name="value" label="Nilai project (Rp)" type="number" :required="true" :value="old('value', $project?->value ?? 0)" min="0" step="1" />
    </div>
</div>
