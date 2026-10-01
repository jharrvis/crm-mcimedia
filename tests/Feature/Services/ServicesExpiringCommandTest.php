<?php

namespace Tests\Feature\Services;

use App\Domains\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesExpiringCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_lists_expiring_and_overdue_services(): void
    {
        $expiring = Service::factory()->create([
            'name' => 'Layanan Segera Habis',
            'end_date' => now()->addDays(5)->toDateString(),
        ]);
        $overdue = Service::factory()->create([
            'name' => 'Layanan Sudah Lewat',
            'end_date' => now()->subDays(3)->toDateString(),
        ]);
        Service::factory()->create([
            'name' => 'Layanan Masih Lama',
            'end_date' => now()->addDays(90)->toDateString(),
        ]);

        $this->artisan('crm:services-expiring')
            ->expectsOutputToContain('Layanan Segera Habis')
            ->expectsOutputToContain('Layanan Sudah Lewat')
            ->doesntExpectOutputToContain('Layanan Masih Lama')
            ->assertSuccessful();
    }

    public function test_command_is_scheduled_daily(): void
    {
        // withSchedule didaftarkan lewat Artisan::starting, jadi verifikasi
        // lewat output schedule:list pada konteks Artisan yang sebenarnya.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('crm:services-expiring')
            ->assertSuccessful();
    }
}
