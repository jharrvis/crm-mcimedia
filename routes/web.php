<?php

use App\Domains\Catalog\Http\Controllers\ProductController;
use App\Domains\Clients\Http\Controllers\ClientContactController;
use App\Domains\Clients\Http\Controllers\ClientController;
use App\Domains\Core\Http\Controllers\ActivityLogController;
use App\Domains\Dashboard\Http\Controllers\DashboardController;
use App\Domains\Invoicing\Http\Controllers\InvoiceController;
use App\Domains\Invoicing\Http\Controllers\PublicInvoiceController;
use App\Domains\Projects\Http\Controllers\ProjectController;
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

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('clients', ClientController::class);
    Route::resource('clients.contacts', ClientContactController::class)->except(['index', 'show']);

    Route::resource('services', ServiceController::class);
    Route::resource('projects', ProjectController::class);
    Route::resource('tasks', TaskController::class);
    Route::patch('tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::patch('tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');

    Route::get('reminders', ReminderController::class)->name('reminders.index');
    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

    // Invoice (F2-2): resource + aksi transisi status + PDF.
    Route::patch('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
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
});
