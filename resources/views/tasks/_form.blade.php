@php
    $task = $task ?? null;
    $isEdit = (bool) $task;
    $clientOptions = ['' => '— Tanpa klien —'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all();
    $projectOptions = ['' => '— Tanpa project —'] + collect($projects)->mapWithKeys(fn ($p) => [$p->id => $p->title])->all();
    $userOptions = ['' => '— Belum ditentukan —'] + collect($users)->mapWithKeys(fn ($u) => [$u->id => $u->name])->all();
    $priorityOptions = collect($priorities)->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
    $statusOptions = collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
@endphp
<div class="grid gap-4">
    <x-input name="title" label="Judul tugas" :required="true" :value="$task?->title" />
    <x-input name="description" label="Deskripsi" type="textarea" rows="3" :value="$task?->description" />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="client_id" label="Klien (opsional)" type="select" :options="$clientOptions"
                 :value="(string) old('client_id', $task?->client_id)" placeholder="" />
        <x-input name="project_id" label="Project (opsional)" type="select" :options="$projectOptions"
                 :value="(string) old('project_id', $task?->project_id)" placeholder="" />
        <x-input name="assigned_user_id" label="Penanggung jawab (opsional)" type="select" :options="$userOptions"
                 :value="(string) old('assigned_user_id', $task?->assigned_user_id)" placeholder="" />
        <x-input name="priority" label="Prioritas" type="select" :required="true" :options="$priorityOptions"
                 :value="old('priority', $task?->priority?->value ?? 'medium')" />
        <x-input name="due_date" label="Due date" type="date" :value="old('due_date', $task?->due_date?->format('Y-m-d'))" />
        @if ($isEdit)
            <x-input name="status" label="Status" type="select" :options="$statusOptions"
                     :value="old('status', $task->status->value)" />
        @endif
    </div>
</div>
