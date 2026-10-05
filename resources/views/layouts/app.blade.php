@php
    // Struktur sidebar grouping (F4-2) + hak akses per modul (F4-1).
    // Item disembunyikan bila user tidak punya akses lihat ke modul terkait.
    // Blok ini di atas <body> karena atribut x-data body butuh $menuOpenDefault.
    $navReminderCount = $reminderCount();
    $nav = [
        ['label' => 'Utama', 'icon' => 'layout-dashboard', 'items' => [
            ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Dashboard', 'module' => null, 'icon' => 'layout-dashboard'],
        ]],
        ['label' => 'Klien dan Layanan', 'icon' => 'users', 'items' => [
            ['route' => 'clients.index', 'match' => 'clients.*', 'label' => 'Klien', 'module' => 'clients', 'icon' => 'building-2'],
            ['route' => 'services.index', 'match' => 'services.*', 'label' => 'Layanan', 'module' => 'services', 'icon' => 'wrench'],
        ]],
        ['label' => 'Keuangan', 'icon' => 'receipt-text', 'items' => [
            ['route' => 'invoices.index', 'match' => 'invoices.*', 'label' => 'Invoice', 'module' => 'invoices', 'icon' => 'receipt-text'],
            ['route' => 'recurring-plans.index', 'match' => 'recurring-plans.*', 'label' => 'Recurring', 'module' => 'invoices', 'icon' => 'repeat'],
            ['route' => 'products.index', 'match' => 'products.*', 'label' => 'Produk', 'module' => 'products', 'icon' => 'package-open'],
            ['route' => 'product-categories.index', 'match' => 'product-categories.*', 'label' => 'Kategori Produk', 'module' => 'products', 'icon' => 'tags'],
            ['route' => 'reports.index', 'match' => 'reports.*', 'label' => 'Laporan', 'module' => 'reports', 'icon' => 'file-text'],
        ]],
        ['label' => 'Project', 'icon' => 'folder', 'items' => [
            ['route' => 'projects.index', 'match' => 'projects.*', 'label' => 'Project', 'module' => 'projects', 'icon' => 'folder'],
            ['route' => 'tasks.index', 'match' => 'tasks.*', 'label' => 'Tugas', 'module' => 'tasks', 'icon' => 'list-checks'],
        ]],
        ['label' => 'Keamanan', 'icon' => 'shield-check', 'items' => [
            ['route' => 'security.index', 'match' => 'security.*', 'label' => 'Keamanan', 'module' => 'security', 'icon' => 'shield-check'],
            ['route' => 'security.monitoring.index', 'match' => 'security.monitoring.*', 'label' => 'Monitoring Server', 'module' => 'security', 'icon' => 'activity'],
            ['route' => 'hestia.index', 'match' => 'hestia.index', 'label' => 'Sinkron Hestia', 'module' => 'hestia', 'icon' => 'refresh-cw'],
            ['route' => 'hestia.servers.index', 'match' => 'hestia.servers.*', 'label' => 'Server Hestia', 'module' => 'hestia', 'icon' => 'server'],
            ['route' => 'domain-providers.index', 'match' => 'domain-providers.*', 'label' => 'Provider Domain', 'module' => 'providers', 'icon' => 'globe'],
        ]],
        ['label' => 'Lainnya', 'icon' => 'layout-grid', 'items' => [
            ['route' => 'reminders.index', 'match' => 'reminders.*', 'label' => 'Pengingat', 'module' => 'reminders', 'icon' => 'bell', 'badge' => true],
            ['route' => 'activity.index', 'match' => 'activity.*', 'label' => 'Aktivitas', 'module' => 'activity', 'icon' => 'history'],
        ]],
        ['label' => 'Pengaturan', 'icon' => 'settings', 'items' => [
            ['route' => 'users.index', 'match' => 'users.*', 'label' => 'User & Role', 'module' => 'users', 'icon' => 'user-cog'],
        ]],
    ];
    $navSlugs = collect($nav)->mapWithKeys(fn ($g) => [$g['label'] => \Illuminate\Support\Str::slug($g['label'])]);
    // Grup berisi route aktif terbuka by default (JS bool mentah via @js).
    $menuOpenDefault = collect($nav)->mapWithKeys(function ($g) use ($navSlugs) {
        $slug = $navSlugs[$g['label']];

        return [$slug => collect($g['items'])->contains(fn ($i) => request()->routeIs($i['match']))];
    })->all();
@endphp
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
<body class="min-h-full bg-slate-50 text-slate-700 antialiased dark:bg-slate-950 dark:text-slate-300"
      x-data="{
          sidebar: false,
          sidebarCollapsed: false,
          profileOpen: false,
          menuOpen: @js($menuOpenDefault)
      }">

    
    <!-- Backdrop off-canvas (mobile) -->
    <div x-show="sidebar" x-cloak class="fixed inset-0 z-30 bg-slate-950/40 lg:hidden" @click="sidebar=false"></div>

    <!-- Sidebar -->
    <aside class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full border-r border-slate-200 bg-white transition-all duration-300 dark:border-slate-800 dark:bg-slate-900 lg:translate-x-0"
           :class="[sidebar ? 'translate-x-0' : '-translate-x-full', sidebarCollapsed ? 'lg:w-20' : 'lg:w-72']">
        <div class="flex h-20 items-center border-b border-slate-100 px-4 dark:border-slate-800" :class="sidebarCollapsed ? 'lg:justify-center' : 'gap-3'">
            <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-600 font-black text-white">M</div>
            <div x-show="!sidebarCollapsed" x-transition.opacity class="min-w-0">
                <div class="font-bold text-slate-900 dark:text-white">MCI Media</div>
                <div class="text-xs text-slate-400">CRM Internal</div>
            </div>
            <button class="ml-auto grid h-9 w-9 place-items-center rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 lg:hidden" @click="sidebar=false" aria-label="Tutup menu">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>

        <nav class="scrollbar h-[calc(100vh-80px)] overflow-y-auto p-3 text-sm" aria-label="Navigasi utama">
            <p x-show="!sidebarCollapsed" class="px-3 pb-2 pt-2 text-[11px] font-bold uppercase tracking-[.18em] text-slate-400">Menu</p>

            @foreach ($nav as $group)
                @php
                    $slug = $navSlugs[$group['label']];
                    $visibleItems = array_filter($group['items'], fn ($i) => !($i['module'] ?? null) || auth()->user()->canAccessModule($i['module']));
                @endphp
                @if (count($visibleItems) > 0)
                    <div class="mb-1">
                        <button @click="sidebarCollapsed ? sidebarCollapsed=false : menuOpen.{{ $slug }}=!menuOpen.{{ $slug }}"
                                class="flex w-full items-center rounded-xl px-3 py-2.5 font-medium transition hover:bg-slate-100 dark:hover:bg-slate-800"
                                :class="sidebarCollapsed ? 'lg:justify-center' : 'gap-3'"
                                :title="sidebarCollapsed ? '{{ $group['label'] }}' : ''">
                            <i data-lucide="{{ $group['icon'] }}" class="h-5 w-5 shrink-0"></i>
                            <span x-show="!sidebarCollapsed">{{ $group['label'] }}</span>
                            <i x-show="!sidebarCollapsed" data-lucide="chevron-down" class="ml-auto h-4 w-4 transition-transform" :class="menuOpen.{{ $slug }} && 'rotate-180'"></i>
                        </button>

                        <div x-show="menuOpen.{{ $slug }} && !sidebarCollapsed" x-collapse
                             class="ml-5 mt-1 border-l border-slate-200 pl-4 dark:border-slate-700">
                            @foreach ($visibleItems as $item)
                                <a href="{{ route($item['route']) }}"
                                   @click="sidebar=false"
                                   @class([
                                       'flex items-center gap-3 rounded-lg px-3 py-2 font-medium transition',
                                       'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400' => request()->routeIs($item['match']),
                                       'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-800 dark:hover:text-white' => ! request()->routeIs($item['match']),
                                   ])>
                                    <i data-lucide="{{ $item['icon'] }}" class="h-4 w-4 shrink-0"></i>
                                    <span>{{ $item['label'] }}</span>
                                    @if (($item['badge'] ?? false) && $navReminderCount > 0)
                                        <span class="ml-auto rounded-full bg-brand-100 px-2 py-0.5 text-[10px] text-brand-700">{{ $navReminderCount }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>
    </aside>

    <!-- Konten -->
    <div class="transition-all duration-300" :class="sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-72'">
        <header class="sticky top-0 z-30 flex h-20 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 md:px-7">
            <button class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 dark:border-slate-700"
                    @click="window.innerWidth >= 1024 ? sidebarCollapsed=!sidebarCollapsed : sidebar=true"
                    aria-label="Toggle sidebar">
                <i data-lucide="panel-left-close" class="h-5 w-5 transition-transform" :class="sidebarCollapsed && 'rotate-180'"></i>
            </button>

            <h1 class="text-lg font-bold text-slate-900 dark:text-white">@yield('title', 'Dashboard')</h1>

            {{-- ponytail: pencarian global belum ada endpoint-nya; diarahkan ke pencarian klien.
                 Upgrade: buat endpoint /search global lalu ganti action form ini. --}}
            <form method="GET" action="{{ route('clients.index') }}" class="relative hidden max-w-md flex-1 md:block">
                <input name="q" value="{{ request('q') }}" placeholder="Cari klien…"
                       class="w-full rounded-xl border border-slate-200 bg-slate-50 px-10 py-2.5 text-sm outline-none focus:border-brand-500 dark:border-slate-700 dark:bg-slate-800">
                <i data-lucide="search" class="absolute left-3 top-3 h-4 w-4 text-slate-400"></i>
            </form>

            <div class="ml-auto flex items-center gap-2">
                <button id="theme-btn" class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800" aria-label="Ganti tema">
                    <i data-lucide="moon" class="h-5 w-5 dark:hidden"></i>
                    <i data-lucide="sun" class="hidden h-5 w-5 dark:block"></i>
                </button>

                <a href="{{ route('reminders.index') }}" title="Pengingat"
                   class="relative grid h-10 w-10 place-items-center rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                    <i data-lucide="bell" class="h-5 w-5"></i>
                    @if ($navReminderCount > 0)
                        <span class="absolute right-2 top-2 h-2 w-2 rounded-full bg-rose-500"></span>
                    @endif
                </a>

                <div class="relative" @click.outside="profileOpen=false">
                    <button @click="profileOpen=!profileOpen" class="flex items-center gap-3 rounded-xl p-1.5 pr-2 hover:bg-slate-100 dark:hover:bg-slate-800">
                        <div class="hidden text-right sm:block">
                            <div class="text-sm font-semibold text-slate-900 dark:text-white">{{ auth()->user()->name }}</div>
                            <div class="text-xs text-slate-400">Internal</div>
                        </div>
                        <div class="grid h-10 w-10 place-items-center rounded-full bg-gradient-to-br from-brand-500 to-brand-700 font-bold text-white">
                            {{ collect(explode(' ', auth()->user()->name))->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode('') }}
                        </div>
                        <i data-lucide="chevron-down" class="hidden h-4 w-4 text-slate-400 transition-transform sm:block" :class="profileOpen && 'rotate-180'"></i>
                    </button>

                    <div x-show="profileOpen" x-transition x-cloak
                         class="absolute right-0 mt-2 w-60 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                        <div class="border-b border-slate-100 px-3 py-3 dark:border-slate-800">
                            <div class="text-sm font-semibold text-slate-900 dark:text-white">{{ auth()->user()->name }}</div>
                            <div class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</div>
                        </div>
                        <a href="{{ route('profile.password.edit') }}" @click="profileOpen=false"
                           class="mt-1 flex items-center gap-2 rounded-xl px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                            <i data-lucide="key-round" class="h-4 w-4"></i> Ubah kata sandi
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-sm text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">
                                <i data-lucide="log-out" class="h-4 w-4"></i> Keluar
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 sm:p-6 md:p-7">
            @if (session('success'))
                <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-200">
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>

<script>
    document.getElementById('theme-btn')?.addEventListener('click', () => {
        const dark = document.documentElement.classList.toggle('dark');
        try { localStorage.theme = dark ? 'dark' : 'light'; } catch (e) {}
    });
</script>
    @stack('scripts')
</body>
</html>