<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori produk (UX-1). Tabel terpisah (bukan enum) supaya bisa ditambah
 * tanpa migrasi. Produk yang kategorinya dihapus menjadi tanpa kategori
 * (FK nullOnDelete), bukan ikut terhapus.
 */
class ProductCategory extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'name', 'slug', 'description', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function activityLabel(): string
    {
        return "kategori produk {$this->name}";
    }
}
