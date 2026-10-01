<?php

use App\Domains\Clients\Http\Controllers\ClientContactController;
use App\Domains\Clients\Http\Controllers\ClientController;
use App\Domains\Core\Http\Controllers\ActivityLogController;
use App\Domains\Dashboard\Http\Controllers\DashboardController;
use App\Domains\Invoicing\Http\Controllers\InvoiceController;
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
    Route::resource('invoices', InvoiceController::class);

    Route::get('profile/password', [ProfileController::class, 'edit'])->name('profile.password.edit');
    Route::put('profile/password', [ProfileController::class, 'update'])->name('profile.password.update');
});
