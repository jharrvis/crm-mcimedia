<?php

namespace App\Providers;

use App\Domains\Services\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Factory untuk model di app/Domains/* tinggal di Database\Factories langsung.
        Factory::guessFactoryNamesUsing(fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory');

        // Badge jumlah layanan yang butuh perhatian di sidebar.
        View::composer('layouts.app', function ($view) {
            $view->with('reminderCount', function () {
                return Service::expiringSoon(30)->count() + Service::overdue()->count();
            });
        });
    }
}
