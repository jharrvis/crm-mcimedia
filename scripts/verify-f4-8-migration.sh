#!/usr/bin/env bash
# Verifikasi end-to-end upgrade F4-8 pada SQLite file nyata (bukan :memory:):
#   1. migrate sampai sebelum F4-8
#   2. isi data invoice gaya lama (service_id terisi)
#   3. jalankan migrasi F4-8
#   4. pastikan data pindah ke pivot & invoices tidak kehilangan baris
#   5. rollback F4-8, pastikan Kolom service_id + isi datanya kembali
set -euo pipefail
export PATH="/home/ubuntu/tools/php-8.3.32:$PATH"
cd /home/ubuntu/workspace/wt-f4-8

DBFILE=database/database.sqlite
MIG=database/migrations/2026_10_06_100001_create_invoice_service_table.php

rm -f "$DBFILE" && touch "$DBFILE"

echo "== 1. migrate semua kecuali F4-8 =="
php -r '
$mig = "2026_10_06_100001_create_invoice_service_table.php";
rename("database/migrations/".$mig, "/tmp/".$mig);
' 
php artisan migrate --force 2>&1 | tail -2

echo "== 2. isi data gaya lama =="
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
$cid = DB::table("clients")->insertGetId(["name"=>"Indoboga","created_at"=>now(),"updated_at"=>now()]);
$sids = [];
foreach (["Website Utama","Website Toko","Website Blog"] as $n) {
  $sids[] = DB::table("services")->insertGetId(["client_id"=>$cid,"type"=>"hosting","name"=>$n,"start_date"=>"2026-01-01","price"=>300000,"cycle"=>"monthly","status"=>"active","reminder_enabled"=>1,"created_at"=>now(),"updated_at"=>now()]);
}
DB::table("invoices")->insert([
  ["client_id"=>$cid,"service_id"=>$sids[0],"number"=>"INV-202601-0001","issue_date"=>"2026-01-01","due_date"=>"2026-01-15","status"=>"paid","subtotal"=>300000,"total"=>300000,"created_at"=>now(),"updated_at"=>now()],
  ["client_id"=>$cid,"service_id"=>$sids[1],"number"=>"INV-202602-0001","issue_date"=>"2026-02-01","due_date"=>"2026-02-15","status"=>"sent","subtotal"=>300000,"total"=>300000,"created_at"=>now(),"updated_at"=>now()],
  ["client_id"=>$cid,"service_id"=>null,   "number"=>"INV-202603-0001","issue_date"=>"2026-03-01","due_date"=>"2026-03-15","status"=>"draft","subtotal"=>0,"total"=>0,"created_at"=>now(),"updated_at"=>now()],
]);
echo "seeded clients=$cid services=".count($sids)."\n";
'

echo "== 3. jalankan migrasi F4-8 =="
php -r 'rename("/tmp/2026_10_06_100001_create_invoice_service_table.php", "database/migrations/2026_10_06_100001_create_invoice_service_table.php");'
php artisan migrate --force 2>&1 | tail -2

echo "== 4. verifikasi hasil up() =="
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
$inv = DB::table("invoices")->count();
$piv = DB::table("invoice_service")->orderBy("invoice_id")->get(["invoice_id","service_id"])->toArray();
echo "invoices=$inv  service_id_column=".(Schema::hasColumn("invoices","service_id")?"YES":"NO")."\n";
echo "pivot=".json_encode($piv)."\n";
if ($inv !== 3) { throw new Exception("DATA HILANG: invoices=$inv, harusnya 3"); }
if (count($piv) !== 2) { throw new Exception("BACKFILL GAGAL: pivot=".count($piv).", harusnya 2"); }
echo "OK up()\n";
'

echo "== 5. rollback F4-8 (down()) =="
# F4-8 dijalankan di batch sendiri (langkah 3), jadi rollback --step=1 hanya
# menyentuh migrasi ini. File migrasi TIDAK boleh dipindah — rollback perlu
# membacanya untuk menginstansiasi kelas migrasi.
php artisan migrate:rollback --force --step=1 2>&1 | tail -2

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
$rows = DB::table("invoices")->orderBy("number")->get(["number","service_id"])->toArray();
echo "service_id_column=".(Schema::hasColumn("invoices","service_id")?"YES":"NO")."  pivot_table=".(Schema::hasTable("invoice_service")?"YES":"NO")."\n";
echo json_encode($rows)."\n";
if (!Schema::hasColumn("invoices","service_id")) { throw new Exception("down() tidak mengembalikan kolom"); }
if (count(array_filter($rows, fn($r)=>$r->service_id !== null)) !== 2) { throw new Exception("down() kehilangan data service_id"); }
echo "OK down()\n";
'

echo "== 6. migrate naik lagi (idempoten dari kondisi rollback) =="
php artisan migrate --force 2>&1 | tail -2
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
echo "pivot=".DB::table("invoice_service")->count()." invoices=".DB::table("invoices")->count()."\n";
'
echo "== SEMUA OK =="