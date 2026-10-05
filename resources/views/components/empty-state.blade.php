@props([
    'icon' => 'inbox',
    'title' => 'Belum ada data',
    'description' => null,
    'cta' => null,
])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center dark:border-slate-700 dark:bg-slate-900']) }}>
    <div class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-slate-100 text-slate-400 dark:bg-slate-800">
        <i data-lucide="{{ $icon }}" class="h-6 w-6"></i>
    </div>
    <p class="mt-3 text-sm font-semibold text-slate-900 dark:text-white">{{ $title }}</p>
    @if ($description || $slot->isNotEmpty())
        <p class="mx-auto mt-1 max-w-sm text-sm text-slate-400">{{ $description ?? $slot }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
    @if ($cta)
        <div class="mt-4">{{ $cta }}</div>
    @endif
</div>