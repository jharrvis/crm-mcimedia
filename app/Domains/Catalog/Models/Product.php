<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'category_id', 'sku', 'name', 'description', 'sales_price', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'sales_price' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Kategori produk (UX-1). Null = tanpa kategori. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** Varian produk (UX-1), opsional — tanpa varian tetap valid. */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('name');
    }

    /** Urutan default katalog: sort_order lalu nama (alfabetis). */
    protected static function booted(): void
    {
        static::addGlobalScope('ordered', function (Builder $query) {
            $query->orderBy('sort_order')->orderBy('name');
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function activityLabel(): string
    {
        return "produk {$this->name}";
    }
}
