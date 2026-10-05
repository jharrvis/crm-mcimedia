<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MCI Media CRM — Sign In</title>
  <meta name="description" content="Sign in to your CRM and infrastructure monitoring workspace." />
  <script>
    // Terapkan tema sebelum render (hindari flash), selaras dengan layout app.
    try {
      if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
      }
    } catch (e) {}
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
          boxShadow: { soft: '0 24px 80px rgba(15,23,42,.12)', glow: '0 0 0 1px rgba(99,102,241,.12),0 24px 70px rgba(79,70,229,.16)' }
        }
      }
    }
  </script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
    * { box-sizing: border-box; }
    body { font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
    .grid-bg {
      background-image: linear-gradient(rgba(255,255,255,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.06) 1px, transparent 1px);
      background-size: 42px 42px;
      mask-image: linear-gradient(to bottom, black, transparent 92%);
    }
    .orb { filter: blur(1px); }
    .float-a { animation: floatA 8s ease-in-out infinite; }
    .float-b { animation: floatB 10s ease-in-out infinite; }
    @keyframes floatA { 0%,100%{transform:translateY(0) rotate(-3deg)}50%{transform:translateY(-14px) rotate(1deg)} }
    @keyframes floatB { 0%,100%{transform:translateY(0) rotate(3deg)}50%{transform:translateY(12px) rotate(-1deg)} }
  </style>
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-white">
  <main class="min-h-screen lg:grid lg:grid-cols-[1.08fr_.92fr]">
    <section class="relative hidden min-h-screen overflow-hidden bg-slate-950 p-10 text-white lg:flex lg:flex-col lg:justify-between xl:p-14">
      <div class="absolute inset-0 grid-bg opacity-70"></div>
      <div class="orb absolute -left-24 top-24 h-80 w-80 rounded-full bg-indigo-600/25 blur-3xl"></div>
      <div class="orb absolute bottom-0 right-0 h-96 w-96 rounded-full bg-cyan-500/15 blur-3xl"></div>

      <div class="relative z-10 flex items-center gap-3">
        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-500 text-lg font-bold shadow-lg shadow-indigo-500/25">M</div>
        <div>
          <div class="text-lg font-bold tracking-tight">MCI Media</div>
          <div class="text-xs font-medium text-slate-400">CRM & Infrastructure</div>
        </div>
      </div>

      <div class="relative z-10 mx-auto w-full max-w-2xl py-14">
        <div class="mb-7 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[.06] px-3 py-1.5 text-xs font-medium text-slate-300 backdrop-blur">
          <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400"></span></span>
          All systems operational
        </div>
        <h1 class="max-w-xl text-4xl font-bold leading-[1.12] tracking-[-.04em] xl:text-5xl">One workspace to run your clients and infrastructure.</h1>
        <p class="mt-5 max-w-lg text-base leading-7 text-slate-400">Manage customers, invoices, services, servers, security events and operational alerts from one focused dashboard.</p>

        <div class="relative mt-12 h-[330px] max-w-xl">
          <div class="float-a absolute left-0 top-3 w-[78%] rounded-3xl border border-white/10 bg-white/[.07] p-5 shadow-2xl backdrop-blur-xl">
            <div class="flex items-center justify-between">
              <div><p class="text-xs font-medium text-slate-400">Infrastructure health</p><p class="mt-1 text-xl font-bold">99.98% uptime</p></div>
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-400/10 text-emerald-400"><i data-lucide="activity" class="h-5 w-5"></i></div>
            </div>
            <div class="mt-6 flex h-24 items-end gap-2">
              <span class="h-[38%] flex-1 rounded-t bg-indigo-400/35"></span><span class="h-[52%] flex-1 rounded-t bg-indigo-400/40"></span><span class="h-[46%] flex-1 rounded-t bg-indigo-400/40"></span><span class="h-[70%] flex-1 rounded-t bg-indigo-400/50"></span><span class="h-[58%] flex-1 rounded-t bg-indigo-400/45"></span><span class="h-[83%] flex-1 rounded-t bg-indigo-400/60"></span><span class="h-[67%] flex-1 rounded-t bg-indigo-400/50"></span><span class="h-[92%] flex-1 rounded-t bg-indigo-400"></span><span class="h-[76%] flex-1 rounded-t bg-indigo-400/65"></span><span class="h-[88%] flex-1 rounded-t bg-indigo-400/80"></span>
            </div>
          </div>
          <div class="float-b absolute bottom-4 right-0 w-[58%] rounded-3xl border border-white/10 bg-slate-900/80 p-5 shadow-2xl backdrop-blur-xl">
            <div class="flex items-center gap-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-400/10 text-rose-400"><i data-lucide="shield-alert" class="h-5 w-5"></i></div>
              <div><p class="text-sm font-semibold">Threat blocked</p><p class="text-xs text-slate-400">WAF · 12 seconds ago</p></div>
              <span class="ml-auto rounded-full bg-rose-400/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-rose-300">High</span>
            </div>
            <div class="mt-5 grid grid-cols-3 gap-2 text-center">
              <div class="rounded-xl bg-white/[.05] p-3"><p class="text-lg font-bold">24</p><p class="text-[10px] text-slate-500">Servers</p></div>
              <div class="rounded-xl bg-white/[.05] p-3"><p class="text-lg font-bold">128</p><p class="text-[10px] text-slate-500">Clients</p></div>
              <div class="rounded-xl bg-white/[.05] p-3"><p class="text-lg font-bold text-emerald-400">23</p><p class="text-[10px] text-slate-500">Healthy</p></div>
            </div>
          </div>
        </div>
      </div>

      <div class="relative z-10 flex items-center justify-between text-xs text-slate-500">
        <span>© 2026 MCI Media</span><span class="flex items-center gap-1.5"><i data-lucide="shield-check" class="h-3.5 w-3.5"></i> Secure workspace</span>
      </div>
    </section>

    <section class="relative flex min-h-screen items-center justify-center bg-white px-5 py-10 dark:bg-slate-950 sm:px-8 lg:px-12">
      <button id="themeToggle" type="button" class="absolute right-5 top-5 flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-800 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white" aria-label="Toggle theme"><i data-lucide="moon" class="h-4.5 w-4.5"></i></button>

      <div class="w-full max-w-[440px]">
        <div class="mb-9 flex items-center gap-3 lg:hidden">
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white">M</div>
          <div><div class="font-bold">MCI Media</div><div class="text-[11px] text-slate-400">CRM & Infrastructure</div></div>
        </div>

        <div class="mb-8">
          <p class="mb-2 text-sm font-semibold text-indigo-600 dark:text-indigo-400">Welcome back</p>
          <h2 class="text-3xl font-bold tracking-[-.035em] text-slate-950 dark:text-white">Sign in to your workspace</h2>
          <p class="mt-3 text-sm leading-6 text-slate-500 dark:text-slate-400">Enter your account details to continue to the dashboard.</p>
        </div>

        <form method="POST" action="{{ route('login.attempt') }}" id="loginForm" class="space-y-5">
          @csrf
          <div>
            <label for="email" class="mb-2 block text-sm font-semibold text-slate-700 dark:text-slate-300">Email address</label>
            <div class="group relative">
              <i data-lucide="mail" class="pointer-events-none absolute left-3.5 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-slate-400 transition group-focus-within:text-indigo-500"></i>
              <input id="email" name="email" type="email" autocomplete="email" required autofocus value="{{ old('email') }}" placeholder="name@company.com" class="h-12 w-full rounded-xl border border-slate-200 bg-white pl-11 pr-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:border-slate-800 dark:bg-slate-900 dark:text-white dark:placeholder:text-slate-600 @error('email') border-rose-400 @enderror" />
            </div>
            @error('email')
              <p class="mt-1.5 text-xs font-medium text-rose-500">{{ $message }}</p>
            @enderror
          </div>

          <div>
            <label for="password" class="mb-2 block text-sm font-semibold text-slate-700 dark:text-slate-300">Password</label>
            <div class="group relative">
              <i data-lucide="lock-keyhole" class="pointer-events-none absolute left-3.5 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-slate-400 transition group-focus-within:text-indigo-500"></i>
              <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Enter your password" class="h-12 w-full rounded-xl border border-slate-200 bg-white pl-11 pr-12 text-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:border-slate-800 dark:bg-slate-900 dark:text-white dark:placeholder:text-slate-600 @error('password') border-rose-400 @enderror" />
              <button id="togglePassword" type="button" class="absolute right-3 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-white" aria-label="Show password"><i data-lucide="eye" class="h-4.5 w-4.5"></i></button>
            </div>
            @error('password')
              <p class="mt-1.5 text-xs font-medium text-rose-500">{{ $message }}</p>
            @enderror
          </div>

          <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-600 dark:text-slate-400">
            <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
            Keep me signed in
          </label>

          <button type="submit" class="group flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700 hover:shadow-indigo-600/30 focus:outline-none focus:ring-4 focus:ring-indigo-500/20">
            Sign in <i data-lucide="arrow-right" class="h-4 w-4 transition-transform group-hover:translate-x-0.5"></i>
          </button>
        </form>

        <p class="mt-8 text-center text-xs text-slate-400">Akses internal — MCI Media</p>

        <div class="mt-6 flex items-center justify-center gap-5 text-[11px] text-slate-400"><a href="#" class="hover:text-slate-600 dark:hover:text-slate-200">Privacy</a><span>•</span><a href="#" class="hover:text-slate-600 dark:hover:text-slate-200">Terms</a><span>•</span><a href="#" class="hover:text-slate-600 dark:hover:text-slate-200">Help</a></div>
      </div>
    </section>
  </main>

  <script>
    const refreshIcons = () => window.lucide && lucide.createIcons();
    refreshIcons();

    const html = document.documentElement;
    const themeToggle = document.getElementById('themeToggle');

    function updateThemeIcon() {
      themeToggle.innerHTML = `<i data-lucide="${html.classList.contains('dark') ? 'sun' : 'moon'}" class="h-4.5 w-4.5"></i>`;
      refreshIcons();
    }
    updateThemeIcon();
    themeToggle.addEventListener('click', () => {
      const dark = html.classList.toggle('dark');
      try { localStorage.theme = dark ? 'dark' : 'light'; } catch (e) {}
      updateThemeIcon();
    });

    const password = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');
    togglePassword.addEventListener('click', () => {
      const show = password.type === 'password';
      password.type = show ? 'text' : 'password';
      togglePassword.innerHTML = `<i data-lucide="${show ? 'eye-off' : 'eye'}" class="h-4.5 w-4.5"></i>`;
      togglePassword.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      refreshIcons();
    });
  </script>
</body>
</html>
