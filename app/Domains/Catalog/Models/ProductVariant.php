<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Varian produk (UX-1): satu produk induk punya banyak varian
 * (mis. "Hosting" → 1GB / 2GB), masing-masing harga & SKU sendiri.
 * Varian opsional — produk tanpa varian tetap berfungsi seperti biasa.
 */
class ProductVariant extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'product_id', 'name', 'sku', 'sales_price', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sales_price' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function activityLabel(): string
    {
        return "varian {$this->name} produk ".($this->product?->name ?? '#'.$this->product_id);
    }
}
