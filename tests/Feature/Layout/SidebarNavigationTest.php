<?php

namespace Tests\Feature\Layout;

use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ambil hanya blok <nav> sidebar dari halaman agar assertion tidak
     * tertabrak teks konten (mis. statistik "Project berjalan" di dashboard).
     */
    private function sidebarHtml(string $path = '/'): string
    {
        $html = $this->get($path)->assertOk()->getContent();
        $start = strpos($html, '<nav class="space-y-4');
        $this->assertNotFalse($start, 'Blok <nav> sidebar tidak ditemukan.');
        $end = strpos($html, '</nav>', $start);
        $this->assertNotFalse($end, 'Penutup </nav> sidebar tidak ditemukan.');

        return substr($html, $start, $end - $start);
    }

    /**
     * Ambil blok <a>…</a> untuk sebuah item label dari HTML sidebar.
     */
    private function anchorFor(string $nav, string $label): string
    {
        $needle = '<span>'.$label.'</span>';
        $labelPos = strpos($nav, $needle);
        $this->assertNotFalse($labelPos, "Item '$label' tidak ditemukan di sidebar.");

        $anchorStart = strrpos(substr($nav, 0, $labelPos), '<a ');
        $anchorEnd = strpos($nav, '</a>', $labelPos);
        $this->assertNotFalse($anchorStart, "Anchor pembuka '$label' tidak ditemukan.");
        $this->assertNotFalse($anchorEnd, "Anchor penutup '$label' tidak ditemukan.");

        return substr($nav, $anchorStart, $anchorEnd - $anchorStart);
    }

    public function test_sidebar_renders_all_groups_and_items_in_declared_order(): void
    {
        $this->actingAs(User::factory()->create());
        $nav = $this->sidebarHtml();

        $expected = [
            'Utama' => ['Dashboard'],
            'Klien dan Layanan' => ['Klien', 'Layanan'],
            'Keuangan' => ['Invoice', 'Produk', 'Laporan'],
            'Project' => ['Project', 'Tugas'],
            'Keamanan' => ['Keamanan', 'Sinkron Hestia'],
            'Lainnya' => ['Pengingat', 'Aktivitas'],
        ];

        $cursor = 0;
        foreach ($expected as $group => $items) {
            $groupPos = strpos($nav, $group, $cursor);
            $this->assertNotFalse($groupPos, "Label grup '$group' tidak ditemukan atau salah urutan.");
            $cursor = $groupPos + strlen($group);

            foreach ($items as $item) {
                $itemPos = strpos($nav, $item, $cursor);
                $this->assertNotFalse($itemPos, "Item '$item' pada grup '$group' tidak ditemukan atau salah urutan.");
                $cursor = $itemPos + strlen($item);
            }
        }
    }

    public function test_sidebar_groups_every_menu_item_exactly_once(): void
    {
        $this->actingAs(User::factory()->create());
        $nav = $this->sidebarHtml();

        $labels = ['Dashboard', 'Klien', 'Layanan', 'Invoice', 'Produk', 'Laporan', 'Project', 'Tugas', 'Keamanan', 'Sinkron Hestia', 'Pengingat', 'Aktivitas'];

        foreach ($labels as $label) {
            // Setiap item dirender dalam satu <span>label</span>.
            $this->assertSame(
                1,
                substr_count($nav, '<span>'.$label.'</span>'),
                "Item '$label' harus muncul tepat satu kali di sidebar."
            );
        }

        // Enam label grup harus dirender.
        foreach (['Utama', 'Klien dan Layanan', 'Keuangan', 'Project', 'Keamanan', 'Lainnya'] as $group) {
            $this->assertStringContainsString(
                '<p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">'.$group.'</p>',
                $nav,
                "Label grup '$group' tidak dirender sebagai header grup."
            );
        }
    }

    public function test_sidebar_shows_reminder_badge_when_services_need_attention(): void
    {
        $this->actingAs(User::factory()->create());

        Service::factory()->create([
            'status' => ServiceStatus::Active,
            'reminder_enabled' => true,
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $nav = $this->sidebarHtml();

        $this->assertMatchesRegularExpression(
            '/<a href="[^"]*reminders[^"]*"[\s\S]*?<span>Pengingat<\/span>[\s\S]*?rounded-full bg-red-500[^>]*>1<\/span>/',
            $nav,
            'Badge jumlah pengingat tidak tampil pada item "Pengingat".'
        );
    }

    public function test_sidebar_hides_reminder_badge_when_nothing_needs_attention(): void
    {
        $this->actingAs(User::factory()->create());

        $nav = $this->sidebarHtml();

        $this->assertStringNotContainsString('rounded-full bg-red-500', $nav);
    }

    public function test_sidebar_highlights_only_the_active_section(): void
    {
        $this->actingAs(User::factory()->create());

        $nav = $this->sidebarHtml('/clients');

        $this->assertStringContainsString('bg-indigo-600', $this->anchorFor($nav, 'Klien'), 'Item aktif "Klien" tidak di-highlight.');
        $this->assertStringNotContainsString('bg-indigo-600', $this->anchorFor($nav, 'Dashboard'), 'Item "Dashboard" seharusnya tidak aktif di /clients.');
        $this->assertStringNotContainsString('bg-indigo-600', $this->anchorFor($nav, 'Layanan'), 'Item "Layanan" seharusnya tidak aktif di /clients.');
    }
}
