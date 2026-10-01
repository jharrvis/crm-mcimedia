<?php

namespace Database\Seeders;

use App\Domains\Catalog\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Katalog produk awal (hasil bersih export Paper.id).
 * Idempotent: updateOrCreate berdasarkan sku — aman dijalankan berulang.
 * Dipanggil manual (`php artisan db:seed --class=ProductSeeder`) atau lewat
 * DatabaseSeeder saat setup; TIDAK dijalankan otomatis di production.
 */
class ProductSeeder extends Seeder
{
    /** @var array<int, array{sku: string, name: string, sales_price: int}> */
    private const PRODUCTS = [
        ['sku' => 'SKU0001', 'name' => 'Desain Web Standard', 'sales_price' => 1500000],
        ['sku' => 'SKU0002', 'name' => 'Domain web.id', 'sales_price' => 75000],
        ['sku' => 'SKU0022', 'name' => 'Domain or.id', 'sales_price' => 150000],
        ['sku' => 'SKU0005', 'name' => 'Domain TLD .com', 'sales_price' => 250000],
        ['sku' => 'SKU0011', 'name' => 'Domain ccTLD co.id', 'sales_price' => 350000],
        ['sku' => 'SKU0025', 'name' => 'Register New Domain TLD (.id)', 'sales_price' => 350000],
        ['sku' => 'SKU0013', 'name' => 'Renewal Domain ccTLD (sch.id)', 'sales_price' => 125000],
        ['sku' => 'SKU0017', 'name' => 'Renewal Domain ccTLD (web.id)', 'sales_price' => 75000],
        ['sku' => 'SKU0014', 'name' => 'Renewal Domain ccTLD (.id)', 'sales_price' => 350000],
        ['sku' => 'SKU0015', 'name' => 'Renewal Domain TLD (.com)', 'sales_price' => 250000],
        ['sku' => 'SKU0006', 'name' => 'Hosting SG 500MB', 'sales_price' => 550000],
        ['sku' => 'SKU0012', 'name' => 'Hosting 1GB (SG)', 'sales_price' => 1100000],
        ['sku' => 'SKU0010', 'name' => 'Hosting 10GB (SG)', 'sales_price' => 3000000],
        ['sku' => 'SKU0016', 'name' => 'VPS 1 Core 40GB JKT', 'sales_price' => 6318382],
        ['sku' => 'SKU0009', 'name' => 'E-mail Hosting 5GB', 'sales_price' => 35000],
        ['sku' => 'SKU0008', 'name' => 'Google Drive Workspace 2TB', 'sales_price' => 350000],
        ['sku' => 'SKU0018', 'name' => 'Monthly Maintenance', 'sales_price' => 750000],
        ['sku' => 'SKU0029', 'name' => 'Website Maintenance', 'sales_price' => 500000],
        ['sku' => 'SKU0026', 'name' => 'Desain Web Toko Online', 'sales_price' => 2500000],
        ['sku' => 'SKU0031', 'name' => 'Instalasi SLIMs', 'sales_price' => 300000],
        ['sku' => 'SKU0019', 'name' => 'ChatGPT', 'sales_price' => 375000],
        ['sku' => 'SKU0020', 'name' => 'ElevenLab AI Starter', 'sales_price' => 100000],
    ];

    public function run(): void
    {
        foreach (self::PRODUCTS as $product) {
            Product::updateOrCreate(
                ['sku' => $product['sku']],
                [
                    'name' => $product['name'],
                    'sales_price' => $product['sales_price'],
                    'is_active' => true,
                ],
            );
        }

        $this->command->info('Katalog produk siap: '.count(self::PRODUCTS).' produk.');
    }
}
