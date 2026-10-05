@props([
    'variant' => 'primary',
    'href' => null,
    'type' => null,
    'icon' => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition disabled:pointer-events-none disabled:opacity-50';

    $variants = [
        'primary' => 'bg-brand-600 text-white shadow-blue-500/20 hover:bg-brand-700',
        'dark' => 'bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200',
        'outline' => 'border border-slate-200 text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800',
        'danger' => 'bg-rose-600 text-white hover:bg-rose-700',
        'ghost' => 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
    ];

    $classes = $base . ' ' . ($variants[$variant] ?? $variants['primary']);
    $tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @else type="{{ $type ?? 'button' }}" @endif {{ $attributes->merge(['class' => $classes]) }}>
    @if ($icon)
        <i data-lucide="{{ $icon }}" class="h-4 w-4 shrink-0"></i>
    @endif
    {{ $slot }}
</{{ $tag }}>