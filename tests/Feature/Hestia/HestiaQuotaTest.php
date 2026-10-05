<?php

namespace Tests\Feature\Hestia;

use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Services\HestiaQuota;
use App\Models\User;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paket, kuota disk, dan status suspend di UI sinkron Hestia (F4-13).
 *
 * Cakupan: parsing payload Hestia (MB/tanpa batas/flag), pemecahan ke kolom
 * dedicated saat sync, perhitungan persentase, tampilan + filter di `/hestia`.
 */
class HestiaQuotaTest extends TestCase
{
    use InteractsWithHestia;
    use RefreshDatabase;

    private function login(): void
    {
        $this->actingAs(User::factory()->create());
    }

    private function account(array $overrides = []): HestiaAccount
    {
        return HestiaAccount::create(array_merge([
            'external_key' => HestiaAccount::keyFor('mcimedia', 'contoh.com'),
            'hestia_user' => 'mcimedia',
            'domain' => 'contoh.com',
            'plan' => 'default',
            'service_type' => 'hosting',
            'status' => 'active',
            'mapping_status' => 'mapped',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $overrides));
    }

    // ---------- parser HestiaQuota ----------

    public function test_to_megabytes_handles_the_shapes_hestia_actually_sends(): void
    {
        // v-update-web-domain-disk memakai `du -shm` → MB bulat.
        $this->assertSame(512, HestiaQuota::toMegabytes('512'));
        $this->assertSame(512, HestiaQuota::toMegabytes(512));
        $this->assertSame(512, HestiaQuota::toMegabytes('512.4'));

        // Satuan eksplikit (locale lain / input manual) dinormalisasi ke MB.
        $this->assertSame(2048, HestiaQuota::toMegabytes('2G'));
        $this->assertSame(1536, HestiaQuota::toMegabytes('1.5G'));
        $this->assertSame(512, HestiaQuota::toMegabytes('512M'));
        $this->assertSame(1, HestiaQuota::toMegabytes('1024K'));

        // Nilai tak terisi / rusak = "tidak diketahui" (null), bukan 0.
        $this->assertNull(HestiaQuota::toMegabytes(''));
        $this->assertNull(HestiaQuota::toMegabytes('   '));
        $this->assertNull(HestiaQuota::toMegabytes('unlimited'));
        $this->assertNull(HestiaQuota::toMegabytes(null));
        $this->assertNull(HestiaQuota::toMegabytes([]));

        // Negatif diklem ke 0, tidak boleh jadi kuota negatif.
        $this->assertSame(0, HestiaQuota::toMegabytes('-5'));
    }

    public function test_disk_quota_zero_is_kept_as_zero_because_hestia_means_unlimited(): void
    {
        $this->assertSame(0, HestiaQuota::fromUserPayload(['DISK_QUOTA' => '0']));
        $this->assertSame(2048, HestiaQuota::fromUserPayload(['DISK_QUOTA' => '2048']));

        // Field tidak ada = belum dilaporkan (null), bukan 0/tanpa batas.
        $this->assertNull(HestiaQuota::fromUserPayload(['PACKAGE' => 'default']));
    }

    public function test_is_yes_never_guesses_for_unknown_values(): void
    {
        $this->assertTrue(HestiaQuota::isYes('yes'));
        $this->assertTrue(HestiaQuota::isYes('YES'));
        $this->assertTrue(HestiaQuota::isYes(true));
        $this->assertTrue(HestiaQuota::isYes('1'));

        $this->assertFalse(HestiaQuota::isYes('no'));
        $this->assertFalse(HestiaQuota::isYes(false));

        // "tidak diketahui" harus null — bukan false, supaya tidak salah
        // menandai akun sebagai tidak disuspend.
        $this->assertNull(HestiaQuota::isYes(''));
        $this->assertNull(HestiaQuota::isYes(null));
        $this->assertNull(HestiaQuota::isYes('mungkin'));
    }

    // ---------- perhitungan pada model ----------

    public function test_disk_percent_uses_quota_and_is_null_when_quota_is_unlimited(): void
    {
        $account = $this->account(['disk_used' => 512, 'disk_quota' => 2048]);
        $this->assertTrue($account->hasDiskQuota());
        $this->assertSame(25, $account->diskUsagePercent());

        // Kuota 0 = tanpa batas: persentase tidak boleh dihitung (division by zero).
        $unlimited = $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'a.com'),
            'domain' => 'a.com',
            'disk_used' => 999999,
            'disk_quota' => 0,
        ]);
        $this->assertFalse($unlimited->hasDiskQuota());
        $this->assertNull($unlimited->diskUsagePercent());

        // Kuota belum dilaporkan (null).
        $unknown = $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'b.com'),
            'domain' => 'b.com',
            'disk_used' => 100,
            'disk_quota' => null,
        ]);
        $this->assertNull($unknown->diskUsagePercent());

        // Pemakaian belum dilaporkan: tidak ada yang bisa ditampilkan.
        $noUsage = $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'c.com'),
            'domain' => 'c.com',
            'disk_used' => null,
            'disk_quota' => 2048,
        ]);
        $this->assertNull($noUsage->diskUsagePercent());
    }

    public function test_over_quota_usage_can_exceed_one_hundred_percent(): void
    {
        // Hestia tetap melaporkan pemakaian meski kuota terlampaui; UI harus
        // menampilkan angka jujurnya (bar nanti dibatasi 100% oleh view).
        $account = $this->account(['disk_used' => 3000, 'disk_quota' => 2048]);
        $this->assertSame(146, $account->diskUsagePercent());
    }

    public function test_suspended_covers_domain_level_and_user_level_flags(): void
    {
        $domainSuspended = $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'a.com'),
            'domain' => 'a.com',
            'suspended' => true,
            'user_suspended' => false,
        ]);
        $this->assertTrue($domainSuspended->isSuspended());
        $this->assertSame('Suspend', $domainSuspended->statusLabel());

        $userSuspended = $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'b.com'),
            'domain' => 'b.com',
            'suspended' => false,
            'user_suspended' => true,
        ]);
        $this->assertTrue($userSuspended->isSuspended());
        $this->assertSame('Suspend', $userSuspended->statusLabel());

        $normal = $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'c.com'),
            'domain' => 'c.com',
            'suspended' => false,
            'user_suspended' => false,
        ]);
        $this->assertFalse($normal->isSuspended());
        $this->assertSame('Aktif', $normal->statusLabel());
    }

    // ---------- sync mengisi kolom dedicated ----------

    public function test_sync_splits_quota_and_suspend_flags_into_dedicated_columns(): void
    {
        $this->configureHestia();
        ClientFactory::new()->create(['email' => 'admin@contoh.com']);

        $this->fakeHestia(
            // Level AKUN: paket + kuota paket + status suspend akun.
            ['mcimedia' => ['PACKAGE' => 'pro', 'DISK_QUOTA' => '2048', 'SUSPENDED' => 'no']],
            // Level DOMAIN: pemakaian disk + status suspend domain.
            [
                'mcimedia' => [
                    'contoh.com' => ['IP' => '203.0.113.10', 'DATE' => '2026-01-01', 'U_DISK' => '512', 'SUSPENDED' => 'no'],
                ],
            ]
        );

        $this->artisan('hestia:sync')->assertSuccessful();

        $account = HestiaAccount::where('domain', 'contoh.com')->firstOrFail();
        $this->assertSame('pro', $account->plan);
        $this->assertSame(512, $account->disk_used);
        $this->assertSame(2048, $account->disk_quota);
        $this->assertFalse($account->suspended);
        $this->assertFalse($account->user_suspended);
        $this->assertSame(25, $account->diskUsagePercent());
    }

    public function test_sync_marks_domain_and_user_suspension_separately(): void
    {
        $this->configureHestia();
        ClientFactory::new()->create(['email' => 'admin@a.com']);
        ClientFactory::new()->create(['email' => 'admin@b.com']);

        $this->fakeHestia(
            [
                'suspendeduser' => ['PACKAGE' => 'default', 'DISK_QUOTA' => '1024', 'SUSPENDED' => 'yes'],
                'ok' => ['PACKAGE' => 'default', 'DISK_QUOTA' => '1024', 'SUSPENDED' => 'no'],
            ],
            [
                // Domain disuspend, tapi akunnya tidak.
                'suspendeduser' => [
                    'a.com' => ['IP' => '203.0.113.10', 'DATE' => '2026-01-01', 'U_DISK' => '100', 'SUSPENDED' => 'yes'],
                    'b.com' => ['IP' => '203.0.113.10', 'DATE' => '2026-01-01', 'U_DISK' => '100', 'SUSPENDED' => 'no'],
                ],
                'ok' => ['c.com' => ['IP' => '203.0.113.10', 'DATE' => '2026-01-01', 'U_DISK' => '100', 'SUSPENDED' => 'no']],
            ]
        );

        $this->artisan('hestia:sync')->assertSuccessful();

        $a = HestiaAccount::where('domain', 'a.com')->firstOrFail();
        $this->assertTrue($a->suspended, 'domain a.com disuspend');
        $this->assertTrue($a->user_suspended, 'akun Hestia-nya ikut disuspend');
        $this->assertTrue($a->isSuspended());

        // b.com tidak disuspend sebagai domain, TETAPI akunnya disuspend.
        $b = HestiaAccount::where('domain', 'b.com')->firstOrFail();
        $this->assertFalse($b->suspended, 'domain b.com tidak disuspend');
        $this->assertTrue($b->user_suspended, 'akun Hestia-nya disuspend');
        $this->assertTrue($b->isSuspended(), 'tetap dianggap suspend karena akunnya');

        $c = HestiaAccount::where('domain', 'c.com')->firstOrFail();
        $this->assertFalse($c->isSuspended());
    }

    public function test_resync_refreshes_quota_values_and_clears_a_cleared_suspension(): void
    {
        $this->configureHestia();
        ClientFactory::new()->create(['email' => 'admin@contoh.com']);

        $domains = ['mcimedia' => [
            'contoh.com' => ['IP' => '203.0.113.10', 'DATE' => '2026-01-01', 'U_DISK' => '100', 'SUSPENDED' => 'yes'],
        ]];

        $this->fakeHestia(['mcimedia' => ['PACKAGE' => 'default', 'DISK_QUOTA' => '1024', 'SUSPENDED' => 'no']], $domains);
        $this->artisan('hestia:sync')->assertSuccessful();

        $this->assertTrue(HestiaAccount::where('domain', 'contoh.com')->firstOrFail()->isSuspended());

        // Sync kedua: suspend dilepas + kuota & pemakaian disk naik.
        $this->fakeHestia(
            ['mcimedia' => ['PACKAGE' => 'default', 'DISK_QUOTA' => '2048', 'SUSPENDED' => 'no']],
            ['mcimedia' => ['contoh.com' => ['IP' => '203.0.113.10', 'DATE' => '2026-01-01', 'U_DISK' => '900', 'SUSPENDED' => 'no']]]
        );
        $this->artisan('hestia:sync')->assertSuccessful();

        $account = HestiaAccount::where('domain', 'contoh.com')->firstOrFail();
        $this->assertFalse($account->suspended, 'suspensi lama harus hilang setelah sync ulang');
        $this->assertSame(900, $account->disk_used);
        $this->assertSame(2048, $account->disk_quota);
        $this->assertSame(44, $account->diskUsagePercent());
    }

    public function test_sync_keeps_quotas_null_when_hestia_does_not_report_them(): void
    {
        $this->configureHestia();
        ClientFactory::new()->create(['email' => 'admin@contoh.com']);

        // Payload minimal: tidak ada DISK_QUOTA maupun U_DISK (Hestia lama).
        $this->fakeHestia(
            ['mcimedia' => ['PACKAGE' => 'default']],
            ['mcimedia' => ['contoh.com' => ['IP' => '203.0.113.10', 'DATE' => '2026-01-01', 'SUSPENDED' => 'no']]]
        );

        $this->artisan('hestia:sync')->assertSuccessful();

        $account = HestiaAccount::where('domain', 'contoh.com')->firstOrFail();
        $this->assertNull($account->disk_used);
        $this->assertNull($account->disk_quota);
        $this->assertNull($account->diskUsagePercent());
    }

    // ---------- tampilan di UI ----------

    public function test_index_shows_plan_disk_usage_and_status(): void
    {
        $this->login();
        $this->account(['plan' => 'pro', 'disk_used' => 512, 'disk_quota' => 1024]);
        $this->account([
            'external_key' => HestiaAccount::keyFor('mcimedia', 'penuh.com'),
            'domain' => 'penuh.com',
            'plan' => 'besar',
            'disk_used' => 2000,
            'disk_quota' => 2048,
        ]);

        $this->get(route('hestia.index'))
            ->assertOk()
            ->assertSee('Paket')
            ->assertSee('Pemakaian disk')
            ->assertSee('pro')
            ->assertSee('besar')
            // 512 MB dari 1024 MB = 50%.
            ->assertSee('512 MB / 1,0 GB')
            ->assertSee('(50%)')
            // 2000 MB / 2048 MB ≈ 98% → bar kuning.
            ->assertSee('(98%)');
    }

    public function test_index_marks_unlimited_quota_instead_of_a_percentage(): void
    {
        $this->login();
        $this->account(['disk_used' => 4096, 'disk_quota' => 0]);

        $this->get(route('hestia.index'))
            ->assertOk()
            ->assertSee('4,0 GB / ∞')
            ->assertSee('tanpa batas')
            ->assertDontSee('(200%)');
    }

    /**
     * Regresi: kuota yang BELUM DI LAPORKAN tidak boleh ditampilkan `∞`.
     *
     * `DISK_QUOTA = 0` (tanpa batas) dan `null` (Hestia belum melapor) adalah
     * dua keadaan berbeda. Menampilkan `∞` untuk `null` berarti mengklaim paket
     * tak terbatas tanpa bukti — operator bisa salah memutuskan menambah kuota.
     */
    public function test_index_shows_unknown_quota_as_dash_not_unlimited(): void
    {
        $this->login();
        // Kuota belum dilaporkan, pemakaian disk masih ada.
        $this->account([
            'domain' => 'belumada-kuota.id',
            'disk_used' => 180,
            'disk_quota' => null,
        ]);
        // Bandingkan dengan akun yang benar-benar tanpa batas.
        $this->account([
            'external_key' => HestiaAccount::keyFor('mcimedia', 'tanpabatas.web.id'),
            'domain' => 'tanpabatas.web.id',
            'disk_used' => 4096,
            'disk_quota' => 0,
        ]);

        $content = $this->get(route('hestia.index'))->assertOk()->getContent();

        $this->assertStringContainsString('180 MB / —', $content, 'kuota unknown harus tampil —');
        $this->assertStringContainsString('4,0 GB / ∞', $content, 'kuota 0 harus tampil ∞');
    }

    public function test_disk_quota_suffix_distinguishes_the_three_quota_states(): void
    {
        $limit = $this->account(['disk_used' => 100, 'disk_quota' => 2048]);
        $this->assertTrue($limit->hasDiskQuota());
        $this->assertFalse($limit->hasUnlimitedDiskQuota());
        $this->assertSame('2,0 GB', $limit->diskQuotaSuffix('2,0 GB'));

        $unlimited = $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'a.com'),
            'domain' => 'a.com',
            'disk_used' => 100,
            'disk_quota' => 0,
        ]);
        $this->assertFalse($unlimited->hasDiskQuota());
        $this->assertTrue($unlimited->hasUnlimitedDiskQuota());
        $this->assertSame('∞', $unlimited->diskQuotaSuffix('2,0 GB'));

        $unknown = $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'b.com'),
            'domain' => 'b.com',
            'disk_used' => 100,
            'disk_quota' => null,
        ]);
        $this->assertFalse($unknown->hasDiskQuota());
        $this->assertFalse($unknown->hasUnlimitedDiskQuota(), 'null bukan berarti tanpa batas');
        $this->assertSame('—', $unknown->diskQuotaSuffix('2,0 GB'));
    }

    public function test_index_shows_suspend_badge_and_account_suspension_note(): void
    {
        $this->login();
        $this->account(['suspended' => true, 'status' => 'inactive']);
        $this->account([
            'external_key' => HestiaAccount::keyFor('mcimedia', 'akun-suspend.com'),
            'domain' => 'akun-suspend.com',
            'user_suspended' => true,
        ]);

        $this->get(route('hestia.index'))
            ->assertOk()
            ->assertSee('Suspend')
            ->assertSee('Akun Hestia disuspend');
    }

    public function test_index_renders_summary_cards(): void
    {
        $this->login();
        $this->account(['disk_used' => 512, 'disk_quota' => 1024, 'suspended' => true, 'status' => 'inactive']);

        $this->get(route('hestia.index'))
            ->assertOk()
            ->assertSee('Total akun')
            ->assertSee('Hampir penuh')
            ->assertSee('Total disk terpakai')
            ->assertSee('512 MB');
    }

    // ---------- filter ----------

    public function test_index_can_filter_by_status_suspended(): void
    {
        $this->login();
        $this->account(['domain' => 'normal.com', 'external_key' => HestiaAccount::keyFor('u', 'normal.com')]);
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'suspend.com'),
            'domain' => 'suspend.com',
            'suspended' => true,
        ]);

        $this->get(route('hestia.index', ['status' => 'suspended']))
            ->assertOk()
            ->assertSee('suspend.com')
            ->assertDontSee('normal.com');
    }

    public function test_suspend_filter_also_matches_accounts_suspended_at_user_level(): void
    {
        $this->login();
        // Domain-nya sendiri TIDAK disuspend; akun Hestia pemiliknya yang
        // disuspend. Filter "Suspend" tetap harus memaksanya.
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'akun-suspend.com'),
            'domain' => 'akun-suspend.com',
            'suspended' => false,
            'user_suspended' => true,
        ]);
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'aman.com'),
            'domain' => 'aman.com',
            'suspended' => false,
            'user_suspended' => false,
        ]);

        $this->get(route('hestia.index', ['status' => 'suspended']))
            ->assertOk()
            ->assertSee('akun-suspend.com')
            ->assertDontSee('aman.com');
    }

    /**
     * Regresi: "Aktif" di UI harus berarti aktif DAN tidak disuspend.
     *
     * Kolom `status` (F3-1) hanya menandai "terlihat pada sinkronisasi terakhir",
     * jadi `status=active` tetap bisa berlaku pada akun yang Hestia sudah
     * suspend. Kalau filter/c kartu tidak memperhitungkan suspensi, operator
     * melihat bar berlabel "Suspend" di hasil filter "Aktif" — dan kartu
     * "Aktif" menghitung akun yang faktanya tidak bisa dipakai klien.
     */
    public function test_active_filter_excludes_accounts_that_are_suspended(): void
    {
        $this->login();
        // Ketiganya `status=active`, tapi suspend-nya berbedabeda.
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'aman.com'),
            'domain' => 'aman.com',
            'suspended' => false,
            'user_suspended' => false,
        ]);
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'domain-suspend.com'),
            'domain' => 'domain-suspend.com',
            'suspended' => true,
            'user_suspended' => false,
        ]);
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'akun-suspend.com'),
            'domain' => 'akun-suspend.com',
            'suspended' => false,
            'user_suspended' => true,
        ]);

        $this->get(route('hestia.index', ['status' => 'active']))
            ->assertOk()
            ->assertSee('aman.com')
            ->assertDontSee('domain-suspend.com')
            ->assertDontSee('akun-suspend.com');
    }

    public function test_active_summary_card_excludes_suspended_accounts(): void
    {
        $this->login();
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'aman.com'),
            'domain' => 'aman.com',
            'status' => 'active',
            'suspended' => false,
            'user_suspended' => false,
        ]);
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'suspend.com'),
            'domain' => 'suspend.com',
            'status' => 'active',
            'user_suspended' => true,
        ]);

        $content = $this->get(route('hestia.index'))->assertOk()->getContent();

        // Kartu: Total 2, Aktif 1, Suspend 1.
        $this->assertSame(2, $this->summaryCardValue($content, 'Total akun'));
        $this->assertSame(1, $this->summaryCardValue($content, 'Aktif'), 'akun suspend tidak boleh dihitung sebagai Aktif');
        $this->assertSame(1, $this->summaryCardValue($content, 'Suspend'));
    }

    /** Baca angka pada kartu ringkasan dari HTML yang sudah dirender. */
    private function summaryCardValue(string $html, string $label): ?int
    {
        // Markup DS: label = <p>, nilai = <div> (x-stat-card). Bentuk <p> lama
        // tetap dikenali untuk kompatibilitas bila ada kartu stat non-DS.
        $this->assertSame(
            1,
            preg_match(
                '/'.preg_quote($label, '/').'<\/p>\s*<(?:p|div) class="[^"]*">([^<]*)</',
                $html,
                $matches
            ),
            "kartu ringkasan '{$label}' tidak ditemukan di HTML"
        );

        return (int) $matches[1];
    }

    public function test_index_can_filter_by_plan(): void
    {
        $this->login();
        $this->account(['plan' => 'pro']);
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'ekonomis.com'),
            'domain' => 'ekonomis.com',
            'plan' => 'mini',
        ]);

        $this->get(route('hestia.index', ['plan' => 'mini']))
            ->assertOk()
            ->assertSee('ekonomis.com')
            ->assertDontSee('contoh.com');
    }

    public function test_index_can_filter_accounts_near_their_quota(): void
    {
        $this->login();
        // 90% → masuk "hampir penuh"
        $this->account(['domain' => 'mendekati.com', 'external_key' => HestiaAccount::keyFor('u', 'mendekati.com'), 'disk_used' => 900, 'disk_quota' => 1000]);
        // 10% → tidak
        $this->account(['domain' => 'aman.com', 'external_key' => HestiaAccount::keyFor('u', 'aman.com'), 'disk_used' => 100, 'disk_quota' => 1000]);

        $this->get(route('hestia.index', ['quota' => 'near_limit']))
            ->assertOk()
            ->assertSee('mendekati.com')
            ->assertDontSee('aman.com');
    }

    public function test_unlimited_quota_accounts_are_never_reported_as_near_limit(): void
    {
        $this->login();
        // Kuota 0 (tanpa batas) dengan pemakaian sangat besar: bukan "hampir penuh".
        $this->account(['domain' => 'besar.com', 'external_key' => HestiaAccount::keyFor('u', 'besar.com'), 'disk_used' => 999999, 'disk_quota' => 0]);

        $this->get(route('hestia.index', ['quota' => 'near_limit']))
            ->assertOk()
            ->assertDontSee('besar.com');
    }

    public function test_index_can_search_by_domain_or_user(): void
    {
        $this->login();
        $this->account(['domain' => 'cari-saya.com', 'hestia_user' => 'userlain']);
        $this->account([
            'external_key' => HestiaAccount::keyFor('userlain', 'lain.com'),
            'domain' => 'lain.com',
            'hestia_user' => 'userlain',
        ]);

        $this->get(route('hestia.index', ['q' => 'cari-saya']))
            ->assertOk()
            ->assertSee('cari-saya.com')
            ->assertDontSee('lain.com');
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->login();
        $this->account(['domain' => 'diskon.com']);
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'lain.com'),
            'domain' => 'lain.com',
        ]);

        // "%" tidak boleh jadi wildcard LIKE yang membuat semua baris cocok.
        $this->get(route('hestia.index', ['q' => '%']))
            ->assertOk()
            ->assertDontSee('diskon.com')
            ->assertDontSee('lain.com');
    }

    /**
     * Regresi: `_` harus dicari apa adanya, dan hasilnya harus ditemukan.
     *
     * Domain ber-underscore itu sah (mis. `my_domain.com`). Kalau wildcard
     * di-escape tanpa klausa `ESCAPE`, SQLite menafsirkan backslash sebagai
     * karakter biasa sehingga hasil tidak pernah cocok — fitur yang lolos di
     * produksi MySQL tapi selalu kosong di dev/test.
     */
    public function test_search_finds_domains_containing_a_literal_underscore(): void
    {
        $this->login();
        $this->account(['domain' => 'my_domain.com', 'hestia_user' => 'u']);
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'lainnya.com'),
            'domain' => 'lainnya.com',
        ]);

        $this->get(route('hestia.index', ['q' => 'my_domain']))
            ->assertOk()
            ->assertSee('my_domain.com')
            ->assertDontSee('lainnya.com');
    }

    /** Persis satu baris yang cocok — bukan "cocok semua". */
    public function test_underscore_search_does_not_widen_to_a_wildcard(): void
    {
        $this->login();
        $this->account(['domain' => 'my_domain.com']);
        $this->account([
            'external_key' => HestiaAccount::keyFor('u', 'myXdomain.com'),
            'domain' => 'myXdomain.com',
        ]);

        $this->get(route('hestia.index', ['q' => 'my_domain']))
            ->assertOk()
            ->assertSee('my_domain.com')
            ->assertDontSee('myXdomain.com');
    }

    public function test_unknown_filter_values_are_ignored_rather_than_filtering(): void
    {
        $this->login();
        $this->account(['domain' => 'semuanya.com', 'external_key' => HestiaAccount::keyFor('u', 'semuanya.com')]);

        $this->get(route('hestia.index', ['status' => 'nilai-ngawur', 'quota' => 'nilai-ngawur', 'plan' => '']))
            ->assertOk()
            ->assertSee('semuanya.com');
    }

    public function test_filter_that_matches_nothing_shows_the_empty_state(): void
    {
        $this->login();
        $this->account();

        $this->get(route('hestia.index', ['q' => 'tidak-ada-domain-ini']))
            ->assertOk()
            ->assertSee('Tidak ada akun yang cocok dengan filter');
    }
}
