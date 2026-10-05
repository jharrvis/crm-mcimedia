@props([
    'name',
    'label' => null,
    'type' => 'text',
    'required' => false,
    'value' => null,
    // Select: asosiatif value => label, atau ['value', 'label', 'attrs' => [...]].
    'options' => [],
    'placeholder' => null,
    'hint' => null,
    'inputId' => null,
])

@php
    // Class input terpusat: semua form konsisten + focus ring brand (t_d3c80e85).
    $baseClass = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:ring-brand-900';
    $id = $inputId ?? $name;
    // old() menang atas $value supaya input kembali terisi setelah validasi gagal.
    $current = old($name, $value);
@endphp

<div @class(['mb-4' => $label])>
    @if ($label)
        <label for="{{ $id }}" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">
            {{ $label }}@if ($required)<span class="text-red-600" aria-hidden="true"> *</span>@endif
        </label>
    @endif

    @if ($type === 'textarea')
        <textarea name="{{ $name }}" id="{{ $id }}" rows="{{ $attributes->get('rows', 3) }}" {{ $attributes->except('rows')->merge(['class' => $baseClass]) }}>{{ $current }}</textarea>
    @elseif ($type === 'select')
        <select name="{{ $name }}" id="{{ $id }}" @if ($required) required @endif {{ $attributes->merge(['class' => $baseClass]) }}>
            @if ($placeholder !== null)
                <option value="">{{ $placeholder }}</option>
            @endif
            @if ($options)
                @foreach ($options as $optKey => $option)
                    @php
                        if (is_array($option)) {
                            $optValue = $option['value'] ?? $optKey;
                            $optLabel = $option['label'] ?? '';
                            $optAttrs = $option['attrs'] ?? [];
                        } else {
                            $optValue = $optKey;
                            $optLabel = $option;
                            $optAttrs = [];
                        }
                    @endphp
                    <option value="{{ $optValue }}" @selected((string) $current === (string) $optValue)
                        @foreach ($optAttrs as $attr => $attrValue) {{ $attr }}="{{ $attrValue }}" @endforeach>{{ $optLabel }}</option>
                @endforeach
            @else
                {{ $slot }}
            @endif
        </select>
    @elseif ($type === 'checkbox' || $type === 'radio')
        {{-- Checkbox mentah (tanpa old/value) karena state dikontrol pemanggil via atribut `checked`. --}}
        <input type="{{ $type }}" name="{{ $name }}" id="{{ $id }}" {{ $attributes->merge(['class' => 'rounded border-slate-300 text-brand-600 focus:ring-brand-500']) }} />
    @else
        <input type="{{ $type }}" name="{{ $name }}" id="{{ $id }}"
               value="{{ $current }}" {{ $attributes->merge(['class' => $baseClass]) }} />
    @endif

    @if ($hint)
        <p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>
    @endif

    @error($name)
        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</div>