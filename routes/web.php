<?php

use App\Domains\Catalog\Http\Controllers\ProductController;
use App\Domains\Clients\Http\Controllers\ClientContactController;
use App\Domains\Clients\Http\Controllers\ClientController;
use App\Domains\Core\Http\Controllers\ActivityLogController;
use App\Domains\Dashboard\Http\Controllers\DashboardController;
use App\Domains\Hestia\Http\Controllers\HestiaController;
use App\Domains\Invoicing\Http\Controllers\InvoiceController;
use App\Domains\Invoicing\Http\Controllers\PublicInvoiceController;
use App\Domains\Projects\Http\Controllers\AchievementReportController;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Http\Controllers\ProjectJournalController;
use App\Domains\Reports\Http\Controllers\ReportController;
use App\Domains\Security\Http\Controllers\ClientSecurityPortalController;
use App\Domains\Security\Http\Controllers\SecurityActionController;
use App\Domains\Security\Http\Controllers\SecurityDashboardController;
use App\Domains\Security\Http\Controllers\SecurityIncidentController;
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
    Route::get('security/report/{token}/reports/{report}/download', [SecurityPortalController::class, 'download'])->name('security.portal.download');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('clients', ClientController::class);
    Route::resource('clients.contacts', ClientContactController::class)->except(['index', 'show']);

    Route::resource('services', ServiceController::class);
    Route::resource('projects', ProjectController::class);

    // Jurnal progress project (F3-4).
    Route::resource('projects.journals', ProjectJournalController::class)->only(['store', 'update', 'destroy']);

    // Laporan pencapaian project (F3-4): daftar, generate dari data periode,
    // detail, unduh PDF, hapus.
    Route::get('projects/{project}/reports/{report}/pdf', [AchievementReportController::class, 'pdf'])
        ->name('projects.reports.pdf');
    Route::resource('projects.reports', AchievementReportController::class)
        ->only(['index', 'store', 'show', 'destroy']);

    // Papan kanban tugas (F3-4) — didaftarkan sebelum resource agar "board"
    // tidak tertangkap oleh tasks/{task}.
    Route::get('tasks/board', [TaskController::class, 'board'])->name('tasks.board');
    Route::resource('tasks', TaskController::class);
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status');
    Route::patch('tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::patch('tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');

    Route::get('reminders', ReminderController::class)->name('reminders.index');
    Route::get('reports', ReportController::class)->name('reports.index');
    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

    // Sinkronisasi HestiaCP (F3-1): daftar akun, jalankan sync, pemetaan manual.
    Route::prefix('hestia')->name('hestia.')->group(function () {
        Route::get('/', [HestiaController::class, 'index'])->name('index');
        Route::post('sync', [HestiaController::class, 'sync'])->name('sync');
        Route::patch('accounts/{account}/map', [HestiaController::class, 'map'])->name('accounts.map');
        Route::patch('accounts/{account}/ignore', [HestiaController::class, 'ignore'])->name('accounts.ignore');
    });

    // Invoice (F2-2): resource + aksi transisi status + PDF.
    Route::patch('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    // Pengiriman invoice via email & WhatsApp (F2-5).
    Route::post('invoices/{invoice}/send-email', [InvoiceController::class, 'sendEmail'])->name('invoices.send-email');
    Route::post('invoices/{invoice}/send-whatsapp', [InvoiceController::class, 'sendWhatsapp'])->name('invoices.send-whatsapp');
    Route::patch('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    // Tautan pembayaran publik (magic link F2-4).
    Route::post('invoices/{invoice}/payment-link', [InvoiceController::class, 'generatePaymentLink'])->name('invoices.payment-link.generate');
    Route::delete('invoices/{invoice}/payment-link', [InvoiceController::class, 'revokePaymentLink'])->name('invoices.payment-link.revoke');
    // Verifikasi konfirmasi transfer klien.
    Route::patch('invoices/{invoice}/payments/{payment}/confirm', [InvoiceController::class, 'confirmPendingPayment'])->name('invoices.payments.confirm');
    Route::patch('invoices/{invoice}/payments/{payment}/reject', [InvoiceController::class, 'rejectPendingPayment'])->name('invoices.payments.reject');
    Route::resource('invoices', InvoiceController::class);

    // Katalog produk (F2-3): resource + toggle aktif/nonaktif.
    Route::patch('products/{product}/toggle', [ProductController::class, 'toggle'])->name('products.toggle');
    Route::resource('products', ProductController::class);

    Route::get('profile/password', [ProfileController::class, 'edit'])->name('profile.password.edit');
    Route::put('profile/password', [ProfileController::class, 'update'])->name('profile.password.update');

    // Modul monitoring keamanan (F3-3): dashboard, insiden, jurnal, laporan.
    Route::prefix('security')->name('security.')->group(function () {
        Route::get('/', [SecurityDashboardController::class, 'index'])->name('index');

        Route::patch('reports/{report}/send', [SecurityReportController::class, 'send'])->name('reports.send');
        Route::patch('reports/{report}/send-to-client', [SecurityReportController::class, 'sendToClient'])->name('reports.send-to-client');
        Route::get('reports/{report}/download', [SecurityReportController::class, 'download'])->name('reports.download');
        Route::get('reports', [SecurityReportController::class, 'index'])->name('reports.index');
        Route::get('reports/create', [SecurityReportController::class, 'create'])->name('reports.create');
        Route::post('reports', [SecurityReportController::class, 'store'])->name('reports.store');
        Route::delete('reports/{report}', [SecurityReportController::class, 'destroy'])->name('reports.destroy');

        Route::resource('incidents', SecurityIncidentController::class)->except('show');
        Route::resource('actions', SecurityActionController::class)->except('show');
    });

    // Tautan laporan keamanan publik per klien (magic link F3-3).
    Route::post('clients/{client}/security-portal-link', [ClientSecurityPortalController::class, 'generate'])->name('clients.security-portal.generate');
    Route::delete('clients/{client}/security-portal-link', [ClientSecurityPortalController::class, 'revoke'])->name('clients.security-portal.revoke');
});
