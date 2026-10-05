@props([
    'variant' => 'slate',
    'dot' => false,
])

@php
    // Varian mengikuti palet template: emerald (success), amber (warning),
    // rose (danger), brand (info), slate (netral).
    $classes = [
        'success' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'warning' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        'danger' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
        'info' => 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400',
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-xs font-semibold ' . ($classes[$variant] ?? $classes['slate'])]) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
    @endif
    {{ $slot }}
</span>