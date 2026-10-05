<?php

use App\Console\Commands\EscalateSecurityIncidentsCommand;
use App\Domains\Invoicing\Console\Commands\GenerateRecurringInvoicesCommand;
use App\Domains\Invoicing\Console\Commands\GenerateRenewalInvoicesCommand;
use App\Domains\Invoicing\Console\Commands\SendOverdueRemindersCommand;
use App\Domains\Projects\Console\Commands\GenerateAchievementReportsCommand;
use App\Domains\Security\Console\Commands\WpScanCommand;
use App\Domains\Hestia\Console\Commands\DiskQuotaAlertsCommand;
use App\Domains\Hestia\Console\Commands\HestiaSyncCommand;
use App\Domains\Services\Console\Commands\SendServiceRemindersCommand;
use App\Domains\Services\Console\Commands\ServicesExpiringCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Token API monitoring keamanan (F3-3).
        $middleware->alias([
            'security.api' => \App\Http\Middleware\AuthenticateSecurityApi::class,
            // Hak akses per modul berbasis role (F4-1).
            'permission' => \App\Domains\Access\Http\Middleware\EnsureModuleAccess::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Eskalasi insiden keamanan: cek setiap menit (F3-3).
        $schedule->command('crm:escalate-incidents')->everyMinute();

        // Pengingat jatuh tempo layanan, setiap hari pukul 08:00.
        $schedule->command('crm:services-expiring')->dailyAt('08:00');
        // Reminder WA perpanjangan layanan H-7/H-3/H-1/overdue (t_cc560a11):
        // dijalankan setelah draf invoice perpanjangan (07:30) & daftar
        // jatuh tempo (08:00), sebelum pengingat invoice (08:30).
        $schedule->command('crm:send-service-reminders')->dailyAt('08:15')->withoutOverlapping();
        // Invoice recurring per siklus (F4-11), sebelum pengingat jatuh tempo.
        $schedule->command('crm:generate-recurring-invoices')->dailyAt('07:00');
        // Draf invoice perpanjangan untuk layanan yang segera berakhir, setiap hari pukul 07:30.
        $schedule->command('crm:generate-renewal-invoices')->dailyAt('07:30');
        // Pengingat invoice jatuh tempo (H+1/H+7/H+14), setiap hari pukul 08:30.
        $schedule->command('crm:send-overdue-reminders')->dailyAt('08:30');
        // Sinkronisasi akun hosting/domain dari HestiaCP (F3-1), setiap hari pukul 06:30.
        $schedule->command('hestia:sync')->dailyAt('06:30');
        // Alert kuota disk website (t_afef420a): 80% warning, 90% kritis.
        // Dijalankan setelah hestia:sync supaya angka pemakaian masih segar.
        $schedule->command('crm:disk-quota-alerts')->dailyAt('07:00');
        // WPScan otomatis situs WordPress klien (t_2e555b0b). Dipindai harian
        // sebelum jam kerja; butuh data hestia:sync (06:30) yang sudah berjalan.
        $schedule->command('crm:wpscan')->weeklyOn(0, '02:00')->withoutOverlapping();
        $schedule->command('crm:file-integrity')->dailyAt('03:00')->withoutOverlapping(); // 10 situs/hari bergiliran
    })
    ->withCommands([
        EscalateSecurityIncidentsCommand::class,
        ServicesExpiringCommand::class,
        GenerateRenewalInvoicesCommand::class,
        GenerateRecurringInvoicesCommand::class,
        SendOverdueRemindersCommand::class,
        SendServiceRemindersCommand::class,
        HestiaSyncCommand::class,
        DiskQuotaAlertsCommand::class,
        GenerateAchievementReportsCommand::class,
        WpScanCommand::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();