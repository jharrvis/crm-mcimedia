<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentStatus;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\SecurityActionFactory;
use Database\Factories\SecurityIncidentFactory;
use Database\Factories\SecurityReportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('security.index'))->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_open_incidents_actions_and_report_counts(): void
    {
        $this->login();
        $client = ClientFactory::new()->create(['name' => 'YIARI']);

        SecurityIncidentFactory::new()->create([
            'client_id' => $client->id,
            'severity' => IncidentSeverity::Critical,
            'status' => IncidentStatus::Open,
        ]);
        SecurityIncidentFactory::new()->create([
            'client_id' => $client->id,
            'severity' => IncidentSeverity::Low,
            'status' => IncidentStatus::Resolved,
        ]);
        SecurityActionFactory::new()->create([
            'client_id' => $client->id,
            'acted_at' => now()->toDateString(),
            'action' => 'Perbarui plugin WordPress',
        ]);
        SecurityReportFactory::new()->create(['client_id' => $client->id, 'period' => '2026-09']);

        $response = $this->get(route('security.index'));

        $response->assertOk();
        $response->assertSee('YIARI');
        // Hanya insiden terbuka yang dihitung: 1 kritis.
        $response->assertSee('Kritis: 1');
        $response->assertSee('1 laporan');
    }

    public function test_dashboard_shows_portal_link_controls(): void
    {
        $this->login();
        $withLink = ClientFactory::new()->create(['name' => 'Punya Tautan']);
        $withLink->forceFill(['security_portal_token' => str_repeat('a', 64)])->save();
        ClientFactory::new()->create(['name' => 'Belum Punya']);

        $response = $this->get(route('security.index'));

        $response->assertOk();
        $response->assertSee('Cabut tautan');
        $response->assertSee('Buat tautan');
        $response->assertSee(route('security.portal.show', ['token' => $withLink->security_portal_token]));
    }
}
