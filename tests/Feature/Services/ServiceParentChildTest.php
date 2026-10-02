<?php

namespace Tests\Feature\Services;

use App\Domains\Clients\Models\Client;
use App\Domains\Services\Models\Service;
use App\Models\User;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * F4-9: konsep parent-child untuk layanan domain — subdomain dikelompokkan di
 * bawah domain induknya.
 *
 * Skenario "Pak Mulkani": punya domain induk + beberapa subdomain, tagihan
 * mengikuti tanggal expired domain induk.
 */
class ServiceParentChildTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function domain(Client $client, array $attributes = []): Service
    {
        return Service::factory()->create([
            'client_id' => $client->id,
            'type' => 'domain',
            ...$attributes,
        ]);
    }

    // ---------------------------------------------------------------------
    // Migrasi & model
    // ---------------------------------------------------------------------

    public function test_parent_id_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('services', 'parent_id'));

        $service = Service::factory()->create();

        $this->assertNull($service->parent_id);
        $this->assertFalse($service->isChild());
    }

    public function test_parent_and_children_relations(): void
    {
        $parent = Service::factory()->create(['type' => 'domain', 'name' => 'mulkani.co.id']);
        $child = Service::factory()->create(['type' => 'domain', 'name' => 'www.mulkani.co.id', 'parent_id' => $parent->id]);

        $this->assertTrue($child->isChild());
        $this->assertTrue($child->parent->is($parent));
        $this->assertTrue($parent->children->contains($child));
        $this->assertCount(1, $parent->children()->get());
    }

    public function test_roots_and_subdomains_scopes(): void
    {
        $parent = Service::factory()->create(['type' => 'domain']);
        Service::factory()->create(['type' => 'domain', 'parent_id' => $parent->id]);

        $this->assertSame(1, Service::roots()->count());
        $this->assertSame(1, Service::subdomains()->count());
    }

    public function test_deleting_parent_keeps_children_as_standalone_services(): void
    {
        // nullOnDelete: subdomain tidak boleh ikut terhapus bersama induknya.
        $parent = Service::factory()->create(['type' => 'domain', 'name' => 'mulkani.co.id']);
        $child = Service::factory()->create(['type' => 'domain', 'name' => 'www.mulkani.co.id', 'parent_id' => $parent->id]);

        $parent->delete();

        $this->assertDatabaseMissing('services', ['id' => $parent->id]);
        $this->assertDatabaseHas('services', ['id' => $child->id, 'parent_id' => null]);
    }

    // ---------------------------------------------------------------------
    // Validasi
    // ---------------------------------------------------------------------

    public function test_store_service_with_valid_parent(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id']);

        $response = $this->post(route('services.store'), [
            'client_id' => $client->id,
            'parent_id' => $parent->id,
            'type' => 'domain',
            'name' => 'www.mulkani.co.id',
            'reference' => 'www.mulkani.co.id',
            'cycle' => 'yearly',
            'status' => 'active',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('services', [
            'name' => 'www.mulkani.co.id',
            'parent_id' => $parent->id,
        ]);
    }

    public function test_parent_must_be_a_domain_service(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $hosting = Service::factory()->create(['client_id' => $client->id, 'type' => 'hosting']);

        $response = $this->post(route('services.store'), [
            'client_id' => $client->id,
            'parent_id' => $hosting->id,
            'type' => 'domain',
            'name' => 'www.mulkani.co.id',
            'cycle' => 'yearly',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertDatabaseMissing('services', ['name' => 'www.mulkani.co.id']);
    }

    public function test_parent_must_belong_to_same_client(): void
    {
        $this->login();
        $clientA = ClientFactory::new()->create();
        $clientB = ClientFactory::new()->create();
        $parentOfA = $this->domain($clientA, ['name' => 'mulkani.co.id']);

        $response = $this->post(route('services.store'), [
            'client_id' => $clientB->id,
            'parent_id' => $parentOfA->id,
            'type' => 'domain',
            'name' => 'www.mulkani.co.id',
            'cycle' => 'yearly',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertDatabaseMissing('services', ['name' => 'www.mulkani.co.id']);
    }

    public function test_only_domain_type_may_have_parent(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id']);

        $response = $this->post(route('services.store'), [
            'client_id' => $client->id,
            'parent_id' => $parent->id,
            'type' => 'hosting',
            'name' => 'Hosting www.mulkani.co.id',
            'cycle' => 'yearly',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertDatabaseMissing('services', ['name' => 'Hosting www.mulkani.co.id']);
    }

    public function test_service_cannot_be_its_own_parent(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id']);
        $child = $this->domain($client, ['name' => 'www.mulkani.co.id', 'parent_id' => $parent->id]);

        $response = $this->put(route('services.update', $child), [
            'client_id' => $client->id,
            'parent_id' => $child->id,
            'type' => 'domain',
            'name' => 'www.mulkani.co.id',
            'cycle' => 'yearly',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertDatabaseHas('services', ['id' => $child->id, 'parent_id' => $parent->id]);
    }

    public function test_parent_cannot_be_own_child_creating_a_cycle(): void
    {
        // a -> b -> a harus ditolak.
        $this->login();
        $client = ClientFactory::new()->create();
        $a = $this->domain($client, ['name' => 'mulkani.co.id']);
        $b = $this->domain($client, ['name' => 'www.mulkani.co.id', 'parent_id' => $a->id]);

        $response = $this->put(route('services.update', $a), [
            'client_id' => $client->id,
            'parent_id' => $b->id,
            'type' => 'domain',
            'name' => 'mulkani.co.id',
            'cycle' => 'yearly',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertDatabaseHas('services', ['id' => $a->id, 'parent_id' => null]);
    }

    public function test_update_can_attach_existing_service_to_parent(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id']);
        $child = $this->domain($client, ['name' => 'www.mulkani.co.id']);

        $this->put(route('services.update', $child), [
            'client_id' => $client->id,
            'parent_id' => $parent->id,
            'type' => 'domain',
            'name' => 'www.mulkani.co.id',
            'cycle' => 'yearly',
            'status' => 'active',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('services', ['id' => $child->id, 'parent_id' => $parent->id]);
    }

    public function test_update_can_detach_service_from_parent(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id']);
        $child = $this->domain($client, ['name' => 'www.mulkani.co.id', 'parent_id' => $parent->id]);

        $this->put(route('services.update', $child), [
            'client_id' => $client->id,
            'parent_id' => '',
            'type' => 'domain',
            'name' => 'www.mulkani.co.id',
            'cycle' => 'yearly',
            'status' => 'active',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('services', ['id' => $child->id, 'parent_id' => null]);
    }

    public function test_moving_parent_to_other_client_moves_children_too(): void
    {
        $this->login();
        $clientA = ClientFactory::new()->create();
        $clientB = ClientFactory::new()->create();
        $parent = $this->domain($clientA, ['name' => 'mulkani.co.id']);
        $child = $this->domain($clientA, ['name' => 'www.mulkani.co.id', 'parent_id' => $parent->id]);

        $this->put(route('services.update', $parent), [
            'client_id' => $clientB->id,
            'parent_id' => '',
            'type' => 'domain',
            'name' => 'mulkani.co.id',
            'cycle' => 'yearly',
            'status' => 'active',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('services', ['id' => $parent->id, 'client_id' => $clientB->id]);
        $this->assertDatabaseHas('services', ['id' => $child->id, 'client_id' => $clientB->id]);
    }

    // ---------------------------------------------------------------------
    // Saran domain induk
    // ---------------------------------------------------------------------

    public function test_suggest_parent_matches_domain_suffix(): void
    {
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);

        $this->assertSame($parent->id, Service::suggestParentId('www.mulkani.co.id'));
    }

    public function test_suggest_parent_prefers_most_specific_match(): void
    {
        $client = ClientFactory::new()->create();
        $outer = $this->domain($client, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);
        $inner = $this->domain($client, ['name' => 'toko.mulkani.co.id', 'reference' => 'toko.mulkani.co.id']);

        $this->assertSame($inner->id, Service::suggestParentId('admin.toko.mulkani.co.id'));
        $this->assertSame($outer->id, Service::suggestParentId('www.mulkani.co.id'));
    }

    public function test_suggest_parent_ignores_non_domain_and_exact_match(): void
    {
        $client = ClientFactory::new()->create();
        $this->domain($client, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);
        Service::factory()->create(['client_id' => $client->id, 'type' => 'hosting', 'name' => 'hosting', 'reference' => 'hosting.co.id']);

        // Tidak ada kandidat yang benar-benar merupakan suffix lebih panjang.
        $this->assertNull(Service::suggestParentId('mulkani.co.id'));
        $this->assertNull(Service::suggestParentId('Hosting Bisnis'));
        $this->assertNull(Service::suggestParentId(null));
    }

    public function test_suggest_parent_strips_scheme_and_port(): void
    {
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);

        $this->assertSame($parent->id, Service::suggestParentId('https://www.mulkani.co.id:443/path'));
    }

    public function test_normalize_host(): void
    {
        $this->assertSame('mulkani.co.id', Service::normalizeHost('  MULKANI.CO.ID. '));
        $this->assertSame('mulkani.co.id', Service::normalizeHost('http://mulkani.co.id/admin'));
        $this->assertSame('mulkani.co.id', Service::normalizeHost('mulkani.co.id:8080'));
        $this->assertNull(Service::normalizeHost(''));
        $this->assertNull(Service::normalizeHost(null));
        $this->assertNull(Service::normalizeHost('   '));
    }

    /**
     * Dua klien bisa sama-sama punya `mulkani.co.id`. Tebakan harus mengikuti
     * klien yang dipilih, kalau tidak form akan me-preselect domain klien
     * lain yang nilainya ditolak validasi.
     */
    public function test_suggest_parent_is_scoped_to_the_given_client(): void
    {
        $clientA = ClientFactory::new()->create();
        $clientB = ClientFactory::new()->create();
        $domainA = $this->domain($clientA, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);
        $domainB = $this->domain($clientB, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);

        $this->assertSame($domainA->id, Service::suggestParentId('www.mulkani.co.id', null, $clientA->id));
        $this->assertSame($domainB->id, Service::suggestParentId('www.mulkani.co.id', null, $clientB->id));
    }

    /**
     * Kandidat harus layanan tingkat atas saja — sama persis isi dropdown
     * `parentCandidates()`. Menawarkan subdomain sebagai induk akan menghasilkan
     * pilihan yang tidak bisa dipilih di form.
     */
    public function test_suggest_parent_ignores_services_that_are_already_subdomains(): void
    {
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);
        $sub = $this->domain($client, [
            'name' => 'toko.mulkani.co.id',
            'reference' => 'toko.mulkani.co.id',
            'parent_id' => $parent->id,
        ]);

        $this->assertSame(
            $parent->id,
            Service::suggestParentId('admin.toko.mulkani.co.id', null, $client->id),
            'Domain yang sudah punya induk tidak boleh ditawarkan sebagai induk lagi.',
        );
        $this->assertNotSame($sub->id, Service::suggestParentId('admin.toko.mulkani.co.id', null, $client->id));
    }

    /**
     * Halaman form harus offer kandidat milik klien yang dipilih saja, supaya
     * pre-select tidak pernah mengarah ke domain klien lain.
     */
    public function test_create_form_only_offers_parents_of_the_selected_client(): void
    {
        $this->login();
        $clientA = ClientFactory::new()->create();
        $clientB = ClientFactory::new()->create();
        $this->domain($clientA, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);
        $domainB = $this->domain($clientB, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);

        $html = $this->get(route('services.create', [
            'client_id' => $clientA->id,
            'name' => 'www.mulkani.co.id',
        ]))->getContent();

        $options = $this->parentOptionValues($html);

        $this->assertNotContains(
            (string) $domainB->id,
            $options,
            'Domain klien lain tidak boleh muncul sebagai opsi domain induk.',
        );
        $this->assertCount(
            1,
            $options,
            'Dropdown domain induk hanya boleh berisi domain milik klien terpilih.',
        );
    }

    /**
     * Nilai `value` dari option-option di dalam select domain induk saja.
     *
     * Dicari per-elemen (bukan substring) karena `value="1"` juga muncul di
     * select klien, select jenis, dan atribut lain di halaman yang sama.
     *
     * @return list<string>
     */
    private function parentOptionValues(string $html): array
    {
        $this->assertSame(1, preg_match('/<select[^>]*id="service-parent".*?<\/select>/s', $html, $select));

        preg_match_all('/<option\s+value="([^"]*)"/', $select[0], $matches);

        // Option pertama selalu "— Bukan subdomain —" (value kosong).
        return array_values(array_filter($matches[1], fn ($value) => $value !== ''));
    }

    /**
     * Nama domain berasal dari input admin, jadi harus keluar sebagai teks
     * ter-escape di dalam `<option>` — kalau tidak, satu entry `"><script>`
     * akan jadi XSS tersimpan di halaman form milik klien lain.
     */
    public function test_parent_option_labels_are_escaped(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $this->domain($client, [
            'name' => '<script>alert(1)</script>',
            'reference' => '"><img src=x onerror=alert(2)>',
        ]);

        $html = $this->get(route('services.create', ['client_id' => $client->id]))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ---------------------------------------------------------------------
    // UI
    // ---------------------------------------------------------------------

    public function test_create_form_lists_parent_candidates_and_preselects_suggestion(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);

        $response = $this->get(route('services.create', [
            'client_id' => $client->id,
            'name' => 'www.mulkani.co.id',
        ]));

        $response->assertOk();
        $response->assertSee('Domain induk (subdomain)');
        $response->assertSee('mulkani.co.id');

        // Tebakan otomatis: option domain induk terpilih (Blade menulis `selected` polos).
        $this->assertMatchesRegularExpression(
            '/<option value="'.$parent->id.'"[^>]*\sselected/',
            $response->getContent(),
            'Opsi domain induk yang disarankan harus terpilih.',
        );
    }

    public function test_edit_form_does_not_offer_the_service_itself_as_parent(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id']);
        $child = $this->domain($client, ['name' => 'www.mulkani.co.id', 'parent_id' => $parent->id]);

        $response = $this->get(route('services.edit', $child));

        $response->assertOk();
        $response->assertSee('mulkani.co.id');
        // Opsi milik anak itu sendiri tidak boleh muncul.
        $this->assertNotContains(
            (string) $child->id,
            $this->parentOptionValues($response->getContent()),
            'Layanan tidak boleh menawarkan dirinya sendiri sebagai domain induk.',
        );
    }

    public function test_index_shows_subdomain_grouped_under_its_parent(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, [
            'name' => 'mulkani.co.id',
            'reference' => 'mulkani.co.id',
            'end_date' => now()->addYear()->toDateString(),
        ]);
        $child = $this->domain($client, [
            'name' => 'www.mulkani.co.id',
            'parent_id' => $parent->id,
            'end_date' => now()->addYear()->toDateString(),
        ]);

        $response = $this->get(route('services.index'));

        $response->assertOk();
        $response->assertSee('mulkani.co.id');
        $response->assertSee('www.mulkani.co.id');
        $response->assertSee('Subdomain dari');
        $response->assertSee('↳', false);

        // Induk harus tampil sebelum anak di tabel.
        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, route('services.show', $child)),
            strpos($html, route('services.show', $parent)),
            'Domain induk harus dirender sebelum subdomainnya.',
        );
    }

    public function test_index_search_finds_subdomain(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $this->domain($client, ['name' => 'mulkani.co.id', 'reference' => 'mulkani.co.id']);
        $child = $this->domain($client, ['name' => 'www.mulkani.co.id', 'reference' => 'www.mulkani.co.id']);

        $response = $this->get(route('services.index', ['q' => 'www.mulkani']));

        $response->assertOk();
        $response->assertSee('www.mulkani.co.id');
        $this->assertNotNull($child->id);
    }

    /**
     * Subdomain harus tampil TEPAT di bawah domain induknya, bukan terkumpul
     * di halaman paling bawah. Dua domain dari klien berbeda diurutkan agar
     * bug "semua induk dulu, semua anak belakangan" terdeteksi.
     */
    public function test_index_orders_each_subdomain_directly_under_its_parent(): void
    {
        $this->login();
        $clientA = ClientFactory::new()->create();
        $clientB = ClientFactory::new()->create();

        $parentA = $this->domain($clientA, ['name' => 'AAA mulkani.co.id', 'end_date' => '2030-01-01']);
        $www = $this->domain($clientA, ['name' => 'AAA www', 'parent_id' => $parentA->id, 'end_date' => '2030-01-01']);
        $cpanel = $this->domain($clientA, ['name' => 'AAA cpanel', 'parent_id' => $parentA->id, 'end_date' => '2030-01-01']);
        $parentB = $this->domain($clientB, ['name' => 'BBB toko.co.id', 'end_date' => '2030-01-01']);

        $html = $this->get(route('services.index'))->getContent();
        $pos = fn (Service $s) => strpos($html, route('services.show', $s));

        // Setiap posisi harus ditemukan (baris benar-benar dirender).
        foreach ([$parentA, $www, $cpanel, $parentB] as $service) {
            $this->assertNotFalse($pos($service), "Layanan {$service->name} tidak muncul di daftar.");
        }

        // Induk -> subdomain (cpanel lebih dulu, alphabetical) -> domain lain.
        $this->assertLessThan($pos($cpanel), $pos($parentA), 'Subdomain harus setelah domain induknya.');
        $this->assertLessThan($pos($www), $pos($cpanel), 'Subdomain dalam satu induk diurutkan berdasarkan nama.');
        $this->assertLessThan($pos($parentB), $pos($www), 'Domain lain tidak boleh ikut masuk di tengah grup subdomain.');
    }

    public function test_index_filters_still_work_with_grouped_ordering(): void
    {
        // Self-join pada scope groupedByParent tidak boleh membuat kolom
        // filter (name/type/status/client_id) jadi ambiguous.
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id', 'status' => 'active']);
        $this->domain($client, ['name' => 'www.mulkani.co.id', 'parent_id' => $parent->id, 'status' => 'active']);
        $this->domain($client, ['name' => 'Nonaktif toko.co.id', 'status' => 'inactive']);

        $this->get(route('services.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Nonaktif toko.co.id')
            ->assertDontSee('mulkani.co.id');

        $this->get(route('services.index', ['status' => 'all', 'type' => 'domain']))
            ->assertOk()
            ->assertSee('mulkani.co.id')
            ->assertSee('www.mulkani.co.id');

        $this->get(route('services.index', ['client_id' => $client->id, 'status' => 'all']))
            ->assertOk()
            ->assertSee('www.mulkani.co.id');

        $this->get(route('services.index', ['q' => 'toko', 'status' => 'all']))
            ->assertOk()
            ->assertSee('Nonaktif toko.co.id')
            ->assertDontSee('mulkani.co.id');
    }

    public function test_service_show_displays_parent_link_and_subdomain_list(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id']);
        $child = $this->domain($client, ['name' => 'www.mulkani.co.id', 'parent_id' => $parent->id]);

        // Halaman induk: daftar subdomain.
        $this->get(route('services.show', $parent))
            ->assertOk()
            ->assertSee('Subdomain (1)')
            ->assertSee('www.mulkani.co.id');

        // Halaman anak: tautan ke induk.
        $this->get(route('services.show', $child))
            ->assertOk()
            ->assertSee('Domain induk')
            ->assertSee('mulkani.co.id');
    }

    public function test_client_show_groups_subdomain_under_parent(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $parent = $this->domain($client, ['name' => 'mulkani.co.id']);
        $this->domain($client, ['name' => 'www.mulkani.co.id', 'parent_id' => $parent->id]);

        $response = $this->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertSee('Layanan (2)');
        $response->assertSee('www.mulkani.co.id');
        $response->assertSee('dari mulkani.co.id');
    }

    public function test_destroy_parent_reports_subdomains_kept(): void
    {
        $this->login();
        $parent = Service::factory()->create(['type' => 'domain', 'name' => 'mulkani.co.id']);
        Service::factory()->create(['type' => 'domain', 'name' => 'www.mulkani.co.id', 'parent_id' => $parent->id]);

        $response = $this->delete(route('services.destroy', $parent));

        $response->assertRedirect(route('services.index'));
        $response->assertSessionHas('success', fn (string $message) => str_contains($message, '1 subdomain'));
    }
}
