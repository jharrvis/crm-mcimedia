<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'sku', 'name', 'description', 'sales_price', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sales_price' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
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
