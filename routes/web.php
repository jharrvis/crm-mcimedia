<?php

use App\Domains\Access\Http\Controllers\RoleController;
use App\Domains\Access\Http\Controllers\UserController;
use App\Domains\Catalog\Http\Controllers\ProductCategoryController;
use App\Domains\Catalog\Http\Controllers\ProductController;
use App\Domains\Catalog\Http\Controllers\ProductPickerController;
use App\Domains\Clients\Http\Controllers\ClientContactController;
use App\Domains\Clients\Http\Controllers\ClientController;
use App\Domains\Core\Http\Controllers\ActivityLogController;
use App\Domains\Dashboard\Http\Controllers\DashboardController;
use App\Domains\Hestia\Http\Controllers\HestiaController;
use App\Domains\Hestia\Http\Controllers\HestiaServerController;
use App\Domains\Invoicing\Http\Controllers\InvoiceController;
use App\Domains\Invoicing\Http\Controllers\PublicInvoiceController;
use App\Domains\Invoicing\Http\Controllers\RecurringPlanController;
use App\Domains\Projects\Http\Controllers\AchievementReportController;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Http\Controllers\ProjectJournalController;
use App\Domains\Providers\Http\Controllers\DomainProviderController;
use App\Domains\Reports\Http\Controllers\ReportController;
use App\Domains\Security\Http\Controllers\ClientSecurityPortalController;
use App\Domains\Security\Http\Controllers\MonitoringServerController;
use App\Domains\Security\Http\Controllers\SecurityActionController;
use App\Domains\Security\Http\Controllers\SecurityDashboardController;
use App\Domains\Security\Http\Controllers\SecurityIncidentController;
use App\Domains\Security\Http\Controllers\SecurityMonitoringController;
use App\Domains\Security\Http\Controllers\SecurityPortalController;
use App\Domains\Security\Http\Controllers\SecurityReportController;
use App\Domains\Services\Http\Controllers\ReminderController;
use App\Domains\Services\Http\Controllers\ServiceController;
use App\Domains\Tasks\Http\Controllers\TaskController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
});

// Halaman invoice publik (magic link F2-4) — tanpa login, rate-limited.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('pay/{token}', [PublicInvoiceController::class, 'show'])->name('invoices.public.show');
    Route::get('pay/{token}/pdf', [PublicInvoiceController::class, 'pdf'])->name('invoices.public.pdf');
    Route::post('pay/{token}/payments', [PublicInvoiceController::class, 'storePayment'])->name('invoices.public.payments.store');
});

// Halaman laporan keamanan publik (magic link F3-3) — tanpa login, rate-limited.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('security/report/{token}', [SecurityPortalController::class, 'show'])->name('security.portal.show');

    // F4-4: klien tidak lagi mengunduh PDF langsung; PDF dikirim ke email
    // terdaftar klien. Throttle lebih ketat karena aksi ini mengirim email.
    Route::post('security/report/{token}/reports/{report}/email', [SecurityPortalController::class, 'email'])
        ->middleware('throttle:6,1')
        ->name('security.portal.email');
});

/*
|--------------------------------------------------------------------------
| Area terautentikasi — hak akses per modul (F4-1)
|--------------------------------------------------------------------------
|
| Setiap grup modul diberi middleware `permission:<modul>` (alias dari
| App\Domains\Access\Http\Middleware\EnsureModuleAccess). Action diturunkan
| otomatis: GET index/show = "lihat"; GET create/edit dan semua request tulis
| = "kelola". User tanpa role (akun warisan) & role administrator selalu lolos
| (lihat App\Models\User::isAdmin()).
|
*/
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Dashboard selalu bisa diakses semua user yang login (tanpa gate modul).
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('clients', ClientController::class)->middleware('permission:clients');
    Route::resource('clients.contacts', ClientContactController::class)
        ->except(['index', 'show'])
        ->middleware('permission:clients');

    Route::resource('services', ServiceController::class)->middleware('permission:services');
    Route::resource('projects', ProjectController::class)->middleware('permission:projects');

    // Jurnal progress project (F3-4).
    Route::resource('projects.journals', ProjectJournalController::class)
        ->only(['store', 'update', 'destroy'])
        ->middleware('permission:projects');

    // Laporan pencapaian project (F3-4): daftar, generate dari data periode,
    // detail, unduh PDF, hapus.
    Route::get('projects/{project}/reports/{report}/pdf', [AchievementReportController::class, 'pdf'])
        ->middleware('permission:projects')
        ->name('projects.reports.pdf');
    Route::resource('projects.reports', AchievementReportController::class)
        ->only(['index', 'store', 'show', 'destroy'])
        ->middleware('permission:projects');

    // Papan kanban tugas (F3-4) — didaftarkan sebelum resource agar "board"
    // tidak tertangkap oleh tasks/{task}.
    Route::get('tasks/board', [TaskController::class, 'board'])
        ->middleware('permission:tasks')
        ->name('tasks.board');
    Route::resource('tasks', TaskController::class)->middleware('permission:tasks');
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus'])
        ->middleware('permission:tasks')->name('tasks.status');
    Route::patch('tasks/{task}/complete', [TaskController::class, 'complete'])
        ->middleware('permission:tasks')->name('tasks.complete');
    Route::patch('tasks/{task}/reopen', [TaskController::class, 'reopen'])
        ->middleware('permission:tasks')->name('tasks.reopen');

    Route::get('reminders', ReminderController::class)->middleware('permission:reminders')->name('reminders.index');
    Route::get('reports', ReportController::class)->middleware('permission:reports')->name('reports.index');
    Route::get('activity', [ActivityLogController::class, 'index'])->middleware('permission:activity')->name('activity.index');

    // Sinkronisasi HestiaCP (F3-1): daftar akun, jalankan sync, pemetaan manual.
    // F4-12: `servers` = CRUD server HestiaCP + uji koneksi + sync per server.
    Route::prefix('hestia')->name('hestia.')->middleware('permission:hestia')->group(function () {
        Route::get('/', [HestiaController::class, 'index'])->name('index');
        Route::post('sync', [HestiaController::class, 'sync'])->name('sync');
        Route::patch('accounts/{account}/map', [HestiaController::class, 'map'])->name('accounts.map');
        Route::patch('accounts/{account}/ignore', [HestiaController::class, 'ignore'])->name('accounts.ignore');
    });

    // Registry penyedia domain/hosting (F4-5): CRUD provider, aktif/nonaktif,
    // dan daftar domain dari driver terpilih. Route aksi didaftarkan sebelum
    // resource agar tidak tertangkap oleh domain-providers/{domain_provider}.
    // t_d85674c4: grup ini memakai modul `providers` — sama dengan pemetaan
    // sidebar "Provider Domain". Sebelumnya hanya `auth`, sehingga user tanpa
    // modul providers tetap bisa CRUD provider & membaca kredensialnya.
    Route::middleware('permission:providers')->group(function () {
        Route::get('domain-providers/{domain_provider}/domains', [DomainProviderController::class, 'domains'])
            ->name('domain-providers.domains');
        Route::patch('domain-providers/{domain_provider}/toggle', [DomainProviderController::class, 'toggle'])
            ->name('domain-providers.toggle');
        // F4-7: aksi khusus driver NameSilo — ubah auto-renew & impor ke layanan CRM.
        Route::post('domain-providers/{domain_provider}/auto-renew', [DomainProviderController::class, 'autoRenew'])
            ->name('domain-providers.auto-renew');
        Route::post('domain-providers/{domain_provider}/import-services', [DomainProviderController::class, 'importServices'])
            ->name('domain-providers.import-services');
        Route::resource('domain-providers', DomainProviderController::class);
    });

    // Manajemen server HestiaCP (F4-12) — sg2, YIARI, PA Salatiga, dll.
    // t_d85674c4: modul `hestia` (pemetaan sidebar "Server Hestia"); sebelumnya
    // hanya `auth` sehingga siapa pun yang login bisa ubah/hapus server &
    // memicu sinkronisasi.
    Route::prefix('hestia/servers')->name('hestia.servers.')->middleware('permission:hestia')->group(function () {
        Route::get('/', [HestiaServerController::class, 'index'])->name('index');
        Route::get('create', [HestiaServerController::class, 'create'])->name('create');
        Route::post('/', [HestiaServerController::class, 'store'])->name('store');
        Route::get('{hestia_server}', [HestiaServerController::class, 'show'])->name('show');
        Route::get('{hestia_server}/edit', [HestiaServerController::class, 'edit'])->name('edit');
        Route::put('{hestia_server}', [HestiaServerController::class, 'update'])->name('update');
        Route::delete('{hestia_server}', [HestiaServerController::class, 'destroy'])->name('destroy');
        Route::post('{hestia_server}/test', [HestiaServerController::class, 'test'])->name('test');
        Route::post('{hestia_server}/sync', [HestiaServerController::class, 'sync'])->name('sync');
        // t_dcccffd9: sync bertahap via AJAX — mulai sesi, lalu proses per batch
        // (tombol Sync tidak lagi submit form panjang yang rawan gateway timeout).
        Route::post('{hestia_server}/sync/start', [HestiaServerController::class, 'syncStart'])->name('sync.start');
        Route::post('{hestia_server}/sync/batch', [HestiaServerController::class, 'syncBatch'])->name('sync.batch');
    });

    // Invoice (F2-2): resource + aksi transisi status + PDF.
    Route::patch('invoices/{invoice}/send', [InvoiceController::class, 'send'])
        ->middleware('permission:invoices')->name('invoices.send');
    // Pengiriman invoice via email & WhatsApp (F2-5).
    Route::post('invoices/{invoice}/send-email', [InvoiceController::class, 'sendEmail'])
        ->middleware('permission:invoices')->name('invoices.send-email');
    Route::post('invoices/{invoice}/send-whatsapp', [InvoiceController::class, 'sendWhatsapp'])
        ->middleware('permission:invoices')->name('invoices.send-whatsapp');
    Route::patch('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
        ->middleware('permission:invoices')->name('invoices.cancel');
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])
        ->middleware('permission:invoices')->name('invoices.payments.store');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
        ->middleware('permission:invoices')->name('invoices.pdf');
    // Termin pembayaran (F4-10): pecah nilai kontrak jadi beberapa invoice termin.
    Route::get('invoices/{invoice}/termin/create', [InvoiceController::class, 'createTermin'])
        ->middleware('permission:invoices')->name('invoices.termin.create');
    Route::post('invoices/{invoice}/termin', [InvoiceController::class, 'storeTermin'])
        ->middleware('permission:invoices')->name('invoices.termin.store');
    // Tautan pembayaran publik (magic link F2-4).
    Route::post('invoices/{invoice}/payment-link', [InvoiceController::class, 'generatePaymentLink'])
        ->middleware('permission:invoices')->name('invoices.payment-link.generate');
    Route::delete('invoices/{invoice}/payment-link', [InvoiceController::class, 'revokePaymentLink'])
        ->middleware('permission:invoices')->name('invoices.payment-link.revoke');
    // Verifikasi konfirmasi transfer klien.
    Route::patch('invoices/{invoice}/payments/{payment}/confirm', [InvoiceController::class, 'confirmPendingPayment'])
        ->middleware('permission:invoices')->name('invoices.payments.confirm');
    Route::patch('invoices/{invoice}/payments/{payment}/reject', [InvoiceController::class, 'rejectPendingPayment'])
        ->middleware('permission:invoices')->name('invoices.payments.reject');
    Route::resource('invoices', InvoiceController::class)->middleware('permission:invoices');

    // Paket invoice recurring (F4-11): CRUD paket + aksi tagih/aktivasi.
    // t_d85674c4: modul `invoices` (pemetaan sidebar "Recurring"); sebelumnya
    // hanya `auth` — gap yang sama dengan hestia/servers & domain-providers.
    Route::middleware('permission:invoices')->group(function () {
        Route::post('recurring-plans/{recurringPlan}/generate', [RecurringPlanController::class, 'generateNow'])->name('recurring-plans.generate-now');
        Route::patch('recurring-plans/{recurringPlan}/toggle', [RecurringPlanController::class, 'toggle'])->name('recurring-plans.toggle');
        Route::resource('recurring-plans', RecurringPlanController::class);
    });

    // Picker produk live di form invoice (UX-2): pencarian by nama/SKU
    // (server-side, termasuk varian) + tambah cepat "ke stok" via AJAX.
    // Pencarian memakai izin invoices (formnya di modul invoice); quick-create
    // butuh izin manage modul products (ditentukan di UI lewat data-can-create).
    Route::get('products/picker/search', [ProductPickerController::class, 'search'])
        ->middleware('permission:invoices')->name('products.picker.search');
    Route::post('products/picker/quick-create', [ProductPickerController::class, 'quickCreate'])
        ->middleware('permission:products')->name('products.picker.quick-create');

    // Katalog produk (F2-3): resource + toggle aktif/nonaktif.
    Route::patch('products/{product}/toggle', [ProductController::class, 'toggle'])
        ->middleware('permission:products')->name('products.toggle');
    Route::resource('products', ProductController::class)->middleware('permission:products');

    // Kategori produk (UX-1): CRUD via UI, dipakai untuk grup/filter katalog.
    Route::resource('product-categories', ProductCategoryController::class)->middleware('permission:products');

    Route::get('profile/password', [ProfileController::class, 'edit'])->name('profile.password.edit');
    Route::put('profile/password', [ProfileController::class, 'update'])->name('profile.password.update');

    // Modul monitoring keamanan (F3-3): dashboard, insiden, jurnal, laporan.
    Route::prefix('security')->name('security.')->middleware('permission:security')->group(function () {
        Route::get('/', [SecurityDashboardController::class, 'index'])->name('index');

        Route::prefix('monitoring')->name('monitoring.')->group(function () {
            Route::get('/', [MonitoringServerController::class, 'index'])->name('index');
            Route::get('metrics', [MonitoringServerController::class, 'metrics'])->name('metrics');
            Route::get('health', [MonitoringServerController::class, 'health'])->name('health');

            // Security Monitoring Dashboard (F3-3): ringkasan status, grafik traffic, log serangan, status layanan
            Route::get('dashboard', [SecurityMonitoringController::class, 'index'])->name('dashboard');
            Route::get('dashboard/summary', [SecurityMonitoringController::class, 'summary'])->name('dashboard.summary');
            Route::get('dashboard/traffic', [SecurityMonitoringController::class, 'traffic'])->name('dashboard.traffic');
            Route::get('dashboard/attacks', [SecurityMonitoringController::class, 'attacks'])->name('dashboard.attacks');
            Route::get('dashboard/services', [SecurityMonitoringController::class, 'services'])->name('dashboard.services');
        });

        Route::patch('reports/{report}/send', [SecurityReportController::class, 'send'])->name('reports.send');
        Route::patch('reports/{report}/send-to-client', [SecurityReportController::class, 'sendToClient'])->name('reports.send-to-client');
        Route::get('reports/{report}/download', [SecurityReportController::class, 'download'])->name('reports.download');
        Route::get('reports', [SecurityReportController::class, 'index'])->name('reports.index');
        Route::get('reports/create', [SecurityReportController::class, 'create'])->name('reports.create');
        Route::post('reports', [SecurityReportController::class, 'store'])->name('reports.store');
        Route::delete('reports/{report}', [SecurityReportController::class, 'destroy'])->name('reports.destroy');

        Route::resource('incidents', SecurityIncidentController::class);
        Route::get('incidents/api', [SecurityIncidentController::class, 'api'])->name('incidents.api');
        Route::resource('actions', SecurityActionController::class);
    });

    // Tautan laporan keamanan publik per klien (magic link F3-3).
    Route::post('clients/{client}/security-portal-link', [ClientSecurityPortalController::class, 'generate'])
        ->middleware('permission:clients')->name('clients.security-portal.generate');
    Route::delete('clients/{client}/security-portal-link', [ClientSecurityPortalController::class, 'revoke'])
        ->middleware('permission:clients')->name('clients.security-portal.revoke');

    // Pengaturan › Pengguna & Role (F4-1).
    Route::resource('users', UserController::class)->middleware('permission:users');
    Route::resource('roles', RoleController::class)->middleware('permission:roles');
});
