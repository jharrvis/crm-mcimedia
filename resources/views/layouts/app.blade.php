<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — CRM MCI Media</title>
    <script>
        // Terapkan tema sebelum render (hindari flash).
        try {
            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-800 dark:bg-slate-950 dark:text-slate-200 antialiased">
<div class="flex min-h-full">

    <!-- Sidebar -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 hidden w-64 -translate-x-full bg-slate-900 text-slate-300 transition-transform lg:static lg:z-auto lg:block lg:translate-x-0 dark:bg-slate-900">
        <div class="flex h-16 items-center gap-2 border-b border-white/10 px-5">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 font-bold text-white">M</div>
            <div>
                <p class="text-sm font-bold text-white">CRM MCI Media</p>
                <p class="text-[11px] text-slate-400">Internal</p>
            </div>
        </div>
        <nav class="space-y-4 px-3 py-4 text-sm" aria-label="Navigasi utama">
            @php
                // Struktur sidebar grouping (F4-2) + hak akses per modul (F4-1).
                // Item disembunyikan bila user tidak punya akses lihat ke modul terkait.
                $navReminderCount = $reminderCount();
                $nav = [
                    ['label' => 'Utama', 'items' => [
                        ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Dashboard', 'module' => null],
                    ]],
                    ['label' => 'Klien dan Layanan', 'items' => [
                        ['route' => 'clients.index', 'match' => 'clients.*', 'label' => 'Klien', 'module' => 'clients'],
                        ['route' => 'services.index', 'match' => 'services.*', 'label' => 'Layanan', 'module' => 'services'],
                    ]],
                    ['label' => 'Keuangan', 'items' => [
                        ['route' => 'invoices.index', 'match' => 'invoices.*', 'label' => 'Invoice', 'module' => 'invoices'],
                        ['route' => 'recurring-plans.index', 'match' => 'recurring-plans.*', 'label' => 'Recurring', 'module' => 'invoices'],
                        ['route' => 'products.index', 'match' => 'products.*', 'label' => 'Produk', 'module' => 'products'],
                        ['route' => 'reports.index', 'match' => 'reports.*', 'label' => 'Laporan', 'module' => 'reports'],
                    ]],
                    ['label' => 'Project', 'items' => [
                        ['route' => 'projects.index', 'match' => 'projects.*', 'label' => 'Project', 'module' => 'projects'],
                        ['route' => 'tasks.index', 'match' => 'tasks.*', 'label' => 'Tugas', 'module' => 'tasks'],
                    ]],
                    ['label' => 'Keamanan', 'items' => [
                        ['route' => 'security.index', 'match' => 'security.*', 'label' => 'Keamanan', 'module' => 'security'],
                        ['route' => 'hestia.index', 'match' => 'hestia.*', 'label' => 'Sinkron Hestia', 'module' => 'hestia'],
                        ['route' => 'hestia.servers.index', 'match' => 'hestia.servers.*', 'label' => 'Server Hestia', 'module' => 'hestia'],
                        ['route' => 'domain-providers.index', 'match' => 'domain-providers.*', 'label' => 'Provider Domain', 'module' => 'providers'],
                    ]],
                    ['label' => 'Lainnya', 'items' => [
                        ['route' => 'reminders.index', 'match' => 'reminders.*', 'label' => 'Pengingat', 'module' => 'reminders', 'badge' => true],
                        ['route' => 'activity.index', 'match' => 'activity.*', 'label' => 'Aktivitas', 'module' => 'activity'],
                    ]],
                    ['label' => 'Pengaturan', 'items' => [
                        ['route' => 'users.index', 'match' => 'users.*', 'label' => 'User & Role', 'module' => 'users'],
                    ]],
                ];
            @endphp
            @foreach ($nav as $group)
                @php
                    $visibleItems = array_filter($group['items'], fn($i) => !($i['module'] ?? null) || auth()->user()->canAccessModule($i['module']));
                @endphp
                @if (count($visibleItems) > 0)
                <div class="space-y-1">
                    <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $group['label'] }}</p>
                    @foreach ($visibleItems as $item)
                        <a href="{{ route($item['route']) }}"
                           class="flex items-center justify-between rounded-lg px-3 py-2 font-medium {{ request()->routeIs($item['match']) ? 'bg-indigo-600 text-white' : 'hover:bg-white/5 hover:text-white' }}">
                            <span>{{ $item['label'] }}</span>
                            @if (($item['badge'] ?? false) && $navReminderCount > 0)
                                <span class="rounded-full bg-red-500 px-2 py-0.5 text-[11px] font-bold text-white">{{ $navReminderCount }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
                @endif
            @endforeach
        </nav>
        <div class="mt-auto border-t border-white/10 p-4 text-xs text-slate-500">
            <p class="font-medium text-slate-300">{{ auth()->user()->name }}</p>
            <p class="truncate">{{ auth()->user()->email }}</p>
            <div class="mt-2 flex gap-2">
                <a href="{{ route('profile.password.edit') }}" class="text-indigo-300 hover:text-indigo-200">Ubah kata sandi</a>
                <span class="text-slate-600">·</span>
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button class="text-indigo-300 hover:text-indigo-200">Keluar</button>
                </form>
            </div>
        </div>
    </aside>

    <!-- Konten -->
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6 dark:border-slate-800 dark:bg-slate-900/90">
            <button id="menu-btn" class="rounded-lg p-2 hover:bg-slate-100 lg:hidden dark:hover:bg-slate-800" aria-label="Menu">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="text-lg font-bold">@yield('title', 'Dashboard')</h1>
            <div class="ml-auto flex items-center gap-2">
                <button id="theme-btn" class="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Ganti tema">
                    <svg class="hidden h-5 w-5 dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36 6.36l-.71-.71M6.34 6.34l-.71-.71m12.02 0l-.71.71M6.34 17.66l-.71.71M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <svg class="h-5 w-5 dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg>
                </button>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            @if (session('success'))
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<script>
    document.getElementById('menu-btn')?.addEventListener('click', () => {
        document.getElementById('sidebar').classList.toggle('hidden');
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
    });
    document.getElementById('theme-btn')?.addEventListener('click', () => {
        const dark = document.documentElement.classList.toggle('dark');
        try { localStorage.theme = dark ? 'dark' : 'light'; } catch (e) {}
    });
</script>
</body>
</html>
