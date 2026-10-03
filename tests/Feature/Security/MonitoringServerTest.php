<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Services\NetdataService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringServerTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** @test */
    public function guest_is_redirected_to_login()
    {
        $this->get(route('security.monitoring.index'))
            ->assertRedirect(route('login'));
    }

    /** @test */
    public function monitoring_page_loads_successfully()
    {
        $this->login();

        $response = $this->get(route('security.monitoring.index'));

        $response->assertOk();
        $response->assertViewIs('security.monitoring.index');
        $response->assertSee('Monitoring Server');
        $response->assertSee('sg2');
        $response->assertSee('YIARI');
        $response->assertSee('PA Salatiga');
    }

    /** @test */
    public function metrics_endpoint_returns_json()
    {
        $this->login();

        // Mock Netdata API responses - format asli Netdata v2
        // labels = [time, dim1, dim2, ...], data = [[ts, v1, v2, ...], ...]
        Http::fake([
            'http://100.119.156.82:19999/api/v1/data*' => Http::response([
                'labels' => ['time', 'guest_nice', 'guest', 'steal', 'softirq', 'irq', 'user', 'system', 'nice', 'iowait'],
                'data' => [
                    [time() - 3600, 10.5, 0, 0, 1.0, 0, 15.0, 10.0, 0, 0.5],
                    [time() - 3540, 12.3, 0, 0, 1.2, 0, 12.0, 11.0, 0, 0.3],
                    [time() - 3480, 11.8, 0, 0, 0.9, 0, 10.0, 9.0, 0, 0.4],
                ],
            ], 200),
            'http://100.114.35.33:19999/api/v1/data*' => Http::response([
                'labels' => ['time', 'guest_nice', 'guest', 'steal', 'softirq', 'irq', 'user', 'system', 'nice', 'iowait'],
                'data' => [
                    [time() - 3600, 25.1, 0, 0, 1.0, 0, 25.0, 15.0, 0, 0.5],
                    [time() - 3540, 26.4, 0, 0, 1.1, 0, 26.0, 14.0, 0, 0.4],
                    [time() - 3480, 24.9, 0, 0, 0.8, 0, 24.0, 13.0, 0, 0.3],
                ],
            ], 200),
            'http://100.97.142.93:19999/api/v1/data*' => Http::response([
                'labels' => ['time', 'guest_nice', 'guest', 'steal', 'softirq', 'irq', 'user', 'system', 'nice', 'iowait'],
                'data' => [
                    [time() - 3600, 5.2, 0, 0, 1.0, 0, 5.0, 3.0, 0, 0.5],
                    [time() - 3540, 4.8, 0, 0, 1.2, 0, 4.0, 4.0, 0, 0.3],
                    [time() - 3480, 5.5, 0, 0, 0.9, 0, 5.0, 2.0, 0, 0.4],
                ],
            ], 200),
        ]);

        $response = $this->getJson(route('security.monitoring.metrics', ['after' => 3600, 'points' => 60]));

        $response->assertOk();
        $response->assertJsonStructure([
            'sg2' => ['server_name', 'metrics' => ['cpu', 'ram', 'disk', 'network']],
            'yiari' => ['server_name', 'metrics' => ['cpu', 'ram', 'disk', 'network']],
            'pa-salatiga' => ['server_name', 'metrics' => ['cpu', 'ram', 'disk', 'network']],
        ]);
    }

    /** @test */
    public function metrics_endpoint_caches_results()
    {
        $this->login();

        Http::fake([
            'http://100.119.156.82:19999/api/v1/data*' => Http::response([
                'labels' => ['time', 'guest_nice', 'guest', 'steal', 'softirq', 'irq', 'user', 'system', 'nice', 'iowait'],
                'data' => [
                    [time() - 3600, 10.5, 0, 0, 1.0, 0, 10.0, 5.0, 0, 0.5],
                ],
            ], 200),
            'http://100.114.35.33:19999/api/v1/data*' => Http::response([
                'labels' => ['time', 'guest_nice', 'guest', 'steal', 'softirq', 'irq', 'user', 'system', 'nice', 'iowait'],
                'data' => [
                    [time() - 3600, 25.1, 0, 0, 1.0, 0, 25.0, 10.0, 0, 0.5],
                ],
            ], 200),
            'http://100.97.142.93:19999/api/v1/data*' => Http::response([
                'labels' => ['time', 'guest_nice', 'guest', 'steal', 'softirq', 'irq', 'user', 'system', 'nice', 'iowait'],
                'data' => [
                    [time() - 3600, 5.2, 0, 0, 1.0, 0, 5.0, 2.0, 0, 0.5],
                ],
            ], 200),
        ]);

        // Request pertama - 3 servers × 4 metrics = 12 requests
        $this->getJson(route('security.monitoring.metrics', ['after' => 3600, 'points' => 60]));

        // Reset recorded requests untuk test cache
        Http::assertSentCount(12);

        // Request kedua - harus pakai cache, tidak hit Netdata lagi
        $this->getJson(route('security.monitoring.metrics', ['after' => 3600, 'points' => 60]));

        // Total harus tetap 12 (cache hit)
        Http::assertSentCount(12);
    }

    /** @test */
    public function health_endpoint_checks_all_servers()
    {
        $this->login();

        Http::fake([
            'http://100.119.156.82:19999/api/v1/info' => Http::response(['version' => '1.0'], 200),
            'http://100.114.35.33:19999/api/v1/info' => Http::response([], 500),
            'http://100.97.142.93:19999/api/v1/info' => Http::response(['version' => '1.0'], 200),
        ]);

        $response = $this->getJson(route('security.monitoring.health'));

        $response->assertOk();
        $response->assertJson([
            'sg2' => true,
            'yiari' => false,
            'pa-salatiga' => true,
        ]);
    }

    /** @test */
    public function netdata_service_returns_empty_on_failure()
    {
        Http::fake([
            'http://100.119.156.82:19999/api/v1/data*' => Http::response([], 500),
        ]);

        $service = new NetdataService();
        $result = $service->fetchAllMetrics(3600, 60);

        // Harus tetap return structure tapi data kosong
        $this->assertArrayHasKey('sg2', $result);
        $this->assertArrayHasKey('yiari', $result);
        $this->assertArrayHasKey('pa-salatiga', $result);
        $this->assertEquals([], $result['sg2']['metrics']['cpu']);
    }

    /** @test */
    public function netdata_service_formats_data_correctly()
    {
        $now = time();
        Http::fake([
            'http://100.119.156.82:19999/api/v1/data*' => Http::response([
                'labels' => ['time', 'guest_nice', 'guest', 'steal', 'softirq', 'irq', 'user', 'system', 'nice', 'iowait'],
                'data' => [
                    [$now - 100, 10.5, 0, 0, 1.0, 0, 15.2, 5.2, 0, 0.5],
                    [$now - 50, 15.2, 0, 0, 1.2, 0, 12.8, 6.1, 0, 0.3],
                    [$now, 12.8, 0, 0, 0.9, 0, 10.5, 4.8, 0, 0.4],
                ],
            ], 200),
            'http://100.114.35.33:19999/api/v1/data*' => Http::response([
                'labels' => ['time', 'guest_nice', 'guest', 'steal', 'softirq', 'irq', 'user', 'system', 'nice', 'iowait'],
                'data' => [
                    [$now - 100, 20.1, 0, 0, 1.0, 0, 22.3, 10.5, 0, 0.5],
                    [$now - 50, 22.3, 0, 0, 1.1, 0, 21.5, 11.2, 0, 0.4],
                    [$now, 21.5, 0, 0, 1.0, 0, 20.1, 10.8, 0, 0.3],
                ],
            ], 200),
            'http://100.97.142.93:19999/api/v1/data*' => Http::response([
                'labels' => ['time', 'guest_nice', 'guest', 'steal', 'softirq', 'irq', 'user', 'system', 'nice', 'iowait'],
                'data' => [
                    [$now - 100, 5.0, 0, 0, 1.0, 0, 6.0, 2.5, 0, 0.5],
                    [$now - 50, 6.0, 0, 0, 1.2, 0, 5.5, 3.0, 0, 0.3],
                    [$now, 5.5, 0, 0, 0.9, 0, 5.0, 2.8, 0, 0.4],
                ],
            ], 200),
        ]);

        $service = new NetdataService();
        $result = $service->fetchAllMetrics(3600, 60);

        $cpuData = $result['sg2']['metrics']['cpu'];
        $this->assertCount(3, $cpuData);
        $this->assertEquals($now - 100, $cpuData[0]['time']);
        $this->assertEquals(10.5, $cpuData[0]['value']); // first dimension (guest_nice) at row index 1
        $this->assertEquals(15.2, $cpuData[1]['value']);
        $this->assertEquals(12.8, $cpuData[2]['value']);
    }
}