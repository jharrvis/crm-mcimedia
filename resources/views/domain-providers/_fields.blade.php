{{--
    Field kredensial dinamis (F4-5).
    Membangun form dari definisi DomainProviderDriver::credentialFields(),
    sehingga driver baru otomatis tampil tanpa mengubah view ini.

    Variabel: $fields (skema), $values (nilai non-rahasia), $provider (opsional).
--}}
@php
    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    $existing = isset($provider) && is_array($provider->credentials) ? $provider->credentials : [];
@endphp

@forelse ($fields as $name => $field)
    @php
        $type = $field['type'] ?? 'text';
        $label = $field['label'] ?? $name;
        $required = (bool) ($field['required'] ?? false);
        $secret = (bool) ($field['secret'] ?? false);
        $help = $field['help'] ?? null;
        $value = old("credentials.{$name}", $values[$name] ?? ($field['default'] ?? ''));
        $hasStored = $secret && array_key_exists($name, $existing);
    @endphp

    <div>
        <label for="cred-{{ $name }}" class="mb-1 block text-sm font-medium">
            {{ $label }}@if ($required) <span class="text-red-500">*</span>@endif
        </label>

        @if ($type === 'checkbox')
            <input type="hidden" name="credentials[{{ $name }}]" value="0">
            <label class="inline-flex items-center gap-2 text-sm">
                <input id="cred-{{ $name }}" type="checkbox" name="credentials[{{ $name }}]" value="1"
                       class="rounded border-slate-300 dark:border-slate-700"
                       @checked((bool) old("credentials.{$name}", $values[$name] ?? ($field['default'] ?? false)))>
                <span class="text-slate-500">{{ $help ?? 'Aktifkan bila ya.' }}</span>
            </label>
        @elseif ($type === 'textarea')
            <textarea id="cred-{{ $name }}" name="credentials[{{ $name }}]" rows="5"
                      class="{{ $inputClass }} font-mono text-xs"
                      @if ($required && ! $hasStored) required @endif>{{ $value }}</textarea>
        @elseif ($type === 'password')
            <input id="cred-{{ $name }}" type="password" name="credentials[{{ $name }}]"
                   autocomplete="new-password"
                   class="{{ $inputClass }}"
                   placeholder="{{ $hasStored ? '•••••• (kosongkan untuk tidak mengubah)' : '' }}"
                   @if ($required && ! $hasStored) required @endif>
        @elseif ($type === 'number')
            <input id="cred-{{ $name }}" type="number" name="credentials[{{ $name }}]" value="{{ $value }}"
                   class="{{ $inputClass }}" @if ($required) required @endif>
        @elseif ($type === 'select')
            <select id="cred-{{ $name }}" name="credentials[{{ $name }}]" class="{{ $inputClass }}">
                @foreach (($field['options'] ?? []) as $optValue => $optLabel)
                    <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>
                @endforeach
            </select>
        @else
            <input id="cred-{{ $name }}" type="text" name="credentials[{{ $name }}]" value="{{ $value }}"
                   class="{{ $inputClass }}" @if ($required) required @endif>
        @endif

        @if ($help && $type !== 'checkbox')
            <p class="mt-1 text-xs text-slate-500">{{ $help }}</p>
        @endif

        @error("credentials.{$name}")
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@empty
    <p class="text-sm text-slate-500">Driver ini tidak membutuhkan kredensial.</p>
@endforelse
