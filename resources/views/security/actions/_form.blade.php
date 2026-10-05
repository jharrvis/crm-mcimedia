<div class="grid gap-4 sm:grid-cols-2">
    <x-input name="client_id" type="select" label="Klien" :required="true"
             :options="['' => '— Pilih klien —'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all()"
             :value="(int) old('client_id', $action->client_id)" />

    <x-input name="acted_at" type="date" label="Tanggal tindakan" :required="true"
             :value="old('acted_at', optional($action->acted_at)->format('Y-m-d'))" />
</div>

<x-input name="performed_by" label="Dikerjakan oleh" :value="$action->performed_by" />

<x-input name="action" type="textarea" label="Tindakan" :required="true" :value="$action->action" rows="3" />

<x-input name="result" type="textarea" label="Hasil" :value="$action->result" rows="3" />