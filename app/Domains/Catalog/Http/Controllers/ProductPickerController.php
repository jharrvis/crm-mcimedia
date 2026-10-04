<?php

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencarian produk live (AJAX) + tambah cepat untuk picker produk di form invoice (UX-2).
 *
 * - search(): dipakai dropdown pencarian (debounce) — filter server-side
 *   by nama/SKU produk maupun varian, hanya produk & varian aktif.
 * - quickCreate(): simpan produk baru dari dropdown "Tambahkan ... ke stok"
 *   tanpa reload. Dibatasi permission products.manage (middleware di route).
 *
 * Payload baris selalu berbentuk {id, sku, name, sales_price, variants: [...]}
 * sehingga satu bentuk dipakai oleh hasil pencarian dan produk hasil quick-create.
 */
class ProductPickerController extends Controller
{
    /** Maksimal hasil per pencarian — cukup untuk memori, hemat render. */
    private const LIMIT = 20;

    public function search(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        // Escape wildcard LIKE (_ dan %) agar user input tidak jadi wildcard.
        $like = '%'.addcslashes($q, '%_\\').'%';

        $products = Product::query()
            ->active()
            ->with(['variants' => fn ($v) => $v->active()->orderBy('sort_order')->orderBy('name')])
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    // Varian ikut dicari agar "1GB" menemukan produk induknya.
                    // Pakai scope active() supaya varian nonaktif tidak menyeret produk induknya.
                    ->orWhereHas('variants', fn ($v) => $v->active()->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like));
            }))
            ->limit(self::LIMIT)
            ->get(['id', 'sku', 'name', 'sales_price']);

        return response()->json([
            'query' => $q,
            'products' => $products->map(fn (Product $p) => $this->row($p))->values(),
        ]);
    }

    public function quickCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sales_price' => ['nullable', 'integer', 'min:0'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
        ], [
            'name.required' => 'Nama produk wajib diisi.',
            'name.max' => 'Nama produk maksimal 255 karakter.',
            'sales_price.integer' => 'Harga harus bilangan bulat (IDR).',
            'sales_price.min' => 'Harga tidak boleh negatif.',
            'category_id.exists' => 'Kategori tidak valid.',
        ]);

        $product = Product::create([
            'name' => $validated['name'],
            'sales_price' => (int) ($validated['sales_price'] ?? 0),
            'category_id' => $validated['category_id'] ?? null,
            'sku' => null,
            'description' => null,
            'is_active' => true,
        ]);

        return response()->json(['product' => $this->row($product)], 201);
    }

    /** Bentuk payload baris picker: konsisten untuk pencarian & quick-create. */
    private function row(Product $product): array
    {
        $product->loadMissing('variants');

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'sales_price' => $product->sales_price,
            'category_id' => $product->category_id,
            'variants' => $product->variants
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'name' => $v->name,
                    'sales_price' => $v->sales_price,
                ])->values(),
        ];
    }
}
