<div class="grid gap-4 sm:grid-cols-2">
    <x-input name="client_id" type="select" label="Klien" :required="true"
             :options="['' => '— Pilih klien —'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all()"
             :value="(int) old('client_id', $incident->client_id)" />

    <x-input name="occurred_at" type="datetime-local" label="Waktu kejadian" :required="true"
             :value="old('occurred_at', optional($incident->occurred_at)->format('Y-m-d\TH:i'))" />

    <x-input name="severity" type="select" label="Tingkat keparahan" :required="true"
             :options="collect($severities)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()"
             :value="old('severity', $incident->severity?->value)" />

    <x-input name="source" type="select" label="Sumber temuan" :required="true"
             :options="collect($sources)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()"
             :value="old('source', $incident->source?->value)" />
</div>

<x-input name="title" label="Judul" :required="true" :value="$incident->title" />

<x-input name="description" type="textarea" label="Deskripsi" :value="$incident->description" rows="4" />

<x-input name="status" type="select" label="Status" :required="true"
         :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()"
         :value="old('status', $incident->status?->value ?? 'open')"
         hint="Saat status diubah ke “Selesai”, waktu penyelesaian dicatat otomatis." />