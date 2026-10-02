<?php

namespace Tests\Feature\Services;

use App\Domains\Clients\Models\Client;
use App\Domains\Services\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F4-9: memastikan klaim "hapus domain induk tidak menghapus subdomainnya"
 * benar-benar ditegakkan foreign key, bukan sekadar incidentally berlaku.
 *
 * Test lain (`test_deleting_parent_keeps_children_as_standalone_services`)
 * akan tetap hijau walau FK diabaikan, karena `parent_id` bisa saja kebetulan
 * sudah NULL. Test ini membuktikan dua hal:
 *
 * 1. MySQL/SQLite benar-benar MENOLAK `parent_id` yang menunjuk domain hilang
 *    -> FK parent_id benar-benar aktif di koneksi tes (bukan diam-diam nonaktif).
 * 2. `ON DELETE SET NULL` benar-benar dipakai -> subdomain jadi NULL, bukan
 *    ikut terhapus dan bukan menggantung.
 */
class ForeignKeyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_keys_are_enforced_on_the_test_connection(): void
    {
        $this->assertSame(1, DB::selectOne('PRAGMA foreign_keys')->foreign_keys,
            'Tes SQLite harus berjalan dengan foreign key aktif; kalau 0, test F4-9 lain hanya berbohong.'
        );

        $this->expectException(QueryException::class);

        // Melewati Eloquent agar ReferentialIntegrityCheck tidak mengubah
        // parent_id menjadi NULL dengan diam-diam.
        DB::table('services')->insert([
            'client_id' => Client::factory()->create()->id,
            'parent_id' => 999999, // domain yang tidak ada
            'type' => 'domain',
            'name' => 'www.hantu.test',
            'price' => 0,
            'cycle' => 'yearly',
            'status' => 'active',
            'reminder_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_parent_sets_children_parent_id_to_null_via_the_foreign_key(): void
    {
        $client = Client::factory()->create();
        $parent = Service::factory()->create([
            'client_id' => $client->id,
            'type' => 'domain',
            'name' => 'mulkani.co.id',
        ]);
        $child = Service::factory()->create([
            'client_id' => $client->id,
            'type' => 'domain',
            'name' => 'www.mulkani.co.id',
            'parent_id' => $parent->id,
        ]);

        $this->assertSame($parent->id, $child->fresh()->parent_id);

        DB::table('services')->where('id', $parent->id)->delete();

        $child->refresh();

        $this->assertNotNull($child, 'Subdomain tidak boleh ikut terhapus bersama domain induknya.');
        $this->assertNull($child->parent_id, 'ON DELETE SET NULL harus menyetel parent_id menjadi NULL.');
        $this->assertFalse($child->isChild());
    }
}
