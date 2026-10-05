@props([
    'padded' => true,
    'soft' => true,
])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-200 bg-white shadow-soft dark:border-slate-800 dark:bg-slate-900']) }}>
    @isset($header)
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-5 dark:border-slate-800">
            {{ $header }}
        </div>
    @endisset

    <div class="{{ $padded ? 'p-5' : '' }}">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-slate-100 p-5 dark:border-slate-800">
            {{ $footer }}
        </div>
    @endisset
</div>