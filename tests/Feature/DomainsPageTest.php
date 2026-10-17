<?php

namespace Tests\Feature;

use App\Domains\Access\Enums\Module;
use App\Domains\Access\Models\Role;
use App\Domains\Services\Enums\ServiceType;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\DomainProviderFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Halaman /domains (t_ee922ded): daftar seluruh domain + mapping klien.
 */
class DomainsPageTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_login_required(): void
    {
        $this->get(route('domains.index'))->assertRedirect(route('login'));
    }

    public function test_index_renders_and_lists_domains_only(): void
    {
        $this->login();
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'contoh.com']);
        ServiceFactory::new()->create(['type' => ServiceType::Hosting, 'name' => 'Hosting Rahasia']);

        $this->get(route('domains.index'))
            ->assertOk()
            ->assertSee('contoh.com')
            ->assertDontSee('Hosting Rahasia');
    }

    public function test_search_by_term(): void
    {
        $this->login();
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'lain.net']);

        $this->get(route('domains.index', ['q' => 'mulkani']))
            ->assertOk()
            ->assertSee('mulkani.co.id')
            ->assertDontSee('lain.net');
    }

    public function test_filter_by_provider(): void
    {
        $this->login();
        $provider = DomainProviderFactory::new()->create(['name' => 'NameSilo']);
        $other = DomainProviderFactory::new()->create(['name' => 'Lain']);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'ns.test', 'domain_provider_id' => $provider->id]);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'lain.test', 'domain_provider_id' => $other->id]);

        $this->get(route('domains.index', ['provider' => $provider->id]))
            ->assertOk()
            ->assertSee('ns.test')
            ->assertDontSee('lain.test');
    }

    public function test_filter_by_client(): void
    {
        $this->login();
        $a = ClientFactory::new()->create(['name' => 'Klien A']);
        $b = ClientFactory::new()->create(['name' => 'Klien B']);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'a.test', 'client_id' => $a->id]);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'b.test', 'client_id' => $b->id]);

        $this->get(route('domains.index', ['client_id' => $a->id]))
            ->assertOk()
            ->assertSee('a.test')
            ->assertDontSee('b.test');
    }

    public function test_sort_by_name_desc(): void
    {
        $this->login();
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'aaa.test']);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'zzz.test']);

        $body = $this->get(route('domains.index', ['sort' => 'name', 'dir' => 'desc']))->assertOk()->getContent();
        $this->assertTrue(strpos($body, 'zzz.test') < strpos($body, 'aaa.test'));
    }

    public function test_sort_by_client(): void
    {
        $this->login();
        $klienA = ClientFactory::new()->create(['name' => 'Klien Alpha']);
        $klienZ = ClientFactory::new()->create(['name' => 'Klien Zeta']);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'a.test', 'client_id' => $klienA->id]);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'z.test', 'client_id' => $klienZ->id]);

        $body = $this->get(route('domains.index', ['sort' => 'client', 'dir' => 'asc']))->assertOk()->getContent();
        $this->assertTrue(strpos($body, 'a.test') < strpos($body, 'z.test'));
    }

    public function test_sort_by_expired(): void
    {
        $this->login();
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'exp-early.test', 'end_date' => now()->addDays(5)->toDateString()]);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'exp-late.test', 'end_date' => now()->addDays(90)->toDateString()]);

        $body = $this->get(route('domains.index', ['sort' => 'expired', 'dir' => 'asc']))->assertOk()->getContent();
        $this->assertTrue(strpos($body, 'exp-early.test') < strpos($body, 'exp-late.test'));
    }

    public function test_expiring_domain_gets_highlight_and_badge(): void
    {
        $this->login();
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'badge.test', 'end_date' => now()->addDays(5)->toDateString()]);

        $this->get(route('domains.index'))->assertOk()->assertSee('bg-amber-50');
    }

    public function test_overdue_domain_shown(): void
    {
        $this->login();
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'overdue.test', 'end_date' => now()->subDays(5)->toDateString()]);

        $this->get(route('domains.index'))->assertOk()->assertSee('bg-red-50');
    }

    public function test_search_underscore_not_wildcard(): void
    {
        $this->login();
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'my_domain.test']);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'mydomain.test']);

        $this->get(route('domains.index', ['q' => 'my_domain']))->assertOk()
            ->assertSee('my_domain.test')
            ->assertDontSee('mydomain.test');
    }

    public function test_invalid_provider_and_client_safely_returns_list(): void
    {
        $this->login();
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'still.test']);
        $this->get(route('domains.index', ['provider' => 'abc']))->assertOk()->assertSee('still.test');
        $this->get(route('domains.index', ['client_id' => 'abc']))->assertOk()->assertSee('still.test');
    }

    public function test_provider_none_filter(): void
    {
        $this->login();
        $withProvider = DomainProviderFactory::new()->create();
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'with.test', 'domain_provider_id' => $withProvider->id]);
        ServiceFactory::new()->create(['type' => ServiceType::Domain, 'name' => 'none.test', 'domain_provider_id' => null]);

        $body = $this->get(route('domains.index', ['provider' => 'none']))->assertOk()->getContent();
        $this->assertStringContainsString('none.test', $body);
        $this->assertStringNotContainsString('with.test', $body);
    }

    /**
     * Eager load klien & provider: satu query per relasi, bukan per baris
     * (N+1). Dicek lewat query log: jumlah SELECT ke `clients` /
     * `domain_providers` tidak boleh bertambah saat baris bertambah.
     */
    public function test_no_n_plus_one_queries(): void
    {
        $this->login();
        $provider = DomainProviderFactory::new()->create();

        $countRelationQueries = function (): int {
            DB::flushQueryLog();
            $this->get(route('domains.index'))->assertOk();

            return collect(DB::getQueryLog())
                ->filter(fn (array $q) => preg_match('/from ["`]?(clients|domain_providers)["`]?/i', $q['query']) === 1)
                ->count();
        };

        ServiceFactory::new()->count(2)->create(['type' => ServiceType::Domain, 'domain_provider_id' => $provider->id]);
        DB::enableQueryLog();
        $small = $countRelationQueries();

        ServiceFactory::new()->count(8)->create(['type' => ServiceType::Domain, 'domain_provider_id' => $provider->id]);
        $large = $countRelationQueries();

        $this->assertSame($small, $large, 'Jumlah query relasi bertambah seiring jumlah baris (N+1).');
        // 2 eager load (klien + provider) + 2 query isi dropdown filter.
        $this->assertLessThanOrEqual(4, $large, 'Query relasi melebihi eager load + dropdown filter.');
    }

    public function test_permission_module_services_required(): void
    {
        $role = Role::factory()->views([Module::Clients])->create();
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->get(route('domains.index'))->assertForbidden();

        $viewer = Role::factory()->views([Module::Services])->create();
        $this->actingAs(User::factory()->create(['role_id' => $viewer->id]));

        $this->get(route('domains.index'))->assertOk();
    }

    public function test_sidebar_shows_domain_item_in_domain_and_hosting_group(): void
    {
        $this->login();

        $this->get(route('domains.index'))
            ->assertOk()
            ->assertSee(route('domains.index'), false)
            ->assertSee('<span>Domain</span>', false)
            ->assertSee('Domain dan Hosting');
    }
}
