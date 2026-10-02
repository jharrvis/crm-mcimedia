<?php

use App\Domains\Invoicing\Console\Commands\GenerateRenewalInvoicesCommand;
use App\Domains\Invoicing\Console\Commands\SendOverdueRemindersCommand;
use App\Domains\Projects\Console\Commands\GenerateAchievementReportsCommand;
use App\Domains\Services\Console\Commands\ServicesExpiringCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Pengingat jatuh tempo layanan, setiap hari pukul 08:00.
        $schedule->command('crm:services-expiring')->dailyAt('08:00');
        // Draf invoice perpanjangan untuk layanan yang segera berakhir, setiap hari pukul 07:30.
        $schedule->command('crm:generate-renewal-invoices')->dailyAt('07:30');
        // Pengingat invoice jatuh tempo (H+1/H+7/H+14), setiap hari pukul 08:30.
        $schedule->command('crm:send-overdue-reminders')->dailyAt('08:30');
    })
    ->withCommands([
        ServicesExpiringCommand::class,
        GenerateRenewalInvoicesCommand::class,
        SendOverdueRemindersCommand::class,
        GenerateAchievementReportsCommand::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
