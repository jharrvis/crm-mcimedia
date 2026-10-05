@props([
    'label',
    'value',
    'icon' => null,
    'href' => null,
    'delta' => null,
    'up' => null,
])

@php
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'block rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition dark:border-slate-800 dark:bg-slate-900' . ($href ? ' hover:shadow-md' : '')]) }}>
    <div class="flex items-start justify-between">
        @if ($icon)
            <div class="grid h-11 w-11 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10">
                <i data-lucide="{{ $icon }}" class="h-5 w-5 shrink-0"></i>
            </div>
        @else
            <span></span>
        @endif

        @if ($delta)
            <span @class([
                'rounded-full px-2 py-1 text-xs font-semibold',
                'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10' => $up === true,
                'bg-rose-50 text-rose-600 dark:bg-rose-500/10' => $up === false,
                'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' => $up === null,
            ])>{{ $delta }}</span>
        @endif
    </div>

    <p class="mt-5 text-sm text-slate-400">{{ $label }}</p>
    <div class="mt-1 text-2xl font-bold text-slate-900 dark:text-white">{{ $value }}</div>

    @isset($footer)
        <div class="mt-2 text-xs text-slate-400">{{ $footer }}</div>
    @endisset
</{{ $tag }}>