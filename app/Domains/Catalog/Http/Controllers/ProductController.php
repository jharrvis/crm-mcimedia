<?php

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Catalog\Http\Requests\ProductRequest;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        // "0" = tanpa kategori; string kosong/absen = semua kategori.
        $status = $request->string('status', 'all')->toString();
        $categoryRaw = $request->input('category_id');
        $categoryId = is_string($categoryRaw) && $categoryRaw !== '' ? (int) $categoryRaw : null;

        $products = Product::query()
            ->with(['category', 'variants:id,product_id'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q');
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('sku', 'like', "%{$q}%")
                        // Varian ikut dicari agar "1GB" menemukan produk induknya.
                        ->orWhereHas('variants', fn ($v) => $v
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('sku', 'like', "%{$q}%"));
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $status === 'active'))
            ->when(isset($categoryId), fn ($query) => $categoryRaw === '0'
                ? $query->whereNull('category_id')
                : $query->where('category_id', $categoryId))
            ->paginate(20)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'statusFilter' => $status,
            'categories' => $this->categories(),
            'categoryFilter' => $categoryRaw,
        ]);
    }

    public function create(): View
    {
        return view('products.create', ['categories' => $this->categories()]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $product = Product::create($this->productData($request));
            // Form create tidak punya seksi varian; sync hanya bila penanda dikirim.
            if ($request->boolean('variants_sync')) {
                $this->syncVariants($product, $request->input('variants', []));
            }

            return $product;
        });

        return redirect()->route('products.index')
            ->with('success', "Produk \"{$request->string('name')}\" berhasil ditambahkan.");
    }

    public function edit(Product $product): View
    {
        return view('products.edit', [
            'product' => $product->load('variants'),
            'categories' => $this->categories(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        DB::transaction(function () use ($request, $product) {
            $product->update($this->productData($request));
            // Jika form tanpa seksi varian (request lama / test tanpa variants),
            // varian dibiarkan utuh — backward compatible.
            if ($request->has('variants') || $request->boolean('variants_sync')) {
                $this->syncVariants($product, (array) $request->input('variants', []));
            }
        });

        return redirect()->route('products.index')
            ->with('success', "Produk \"{$product->name}\" berhasil diperbarui.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        $name = $product->name;
        $product->delete(); // varian ikut terhapus (FK cascadeOnDelete)

        return redirect()->route('products.index')
            ->with('success', "Produk \"{$name}\" dihapus.");
    }

    /** Aktif/nonaktifkan produk. Produk nonaktif tidak muncul di picker invoice. */
    public function toggle(Product $product): RedirectResponse
    {
        $product->update(['is_active' => ! $product->is_active]);

        return back()->with('success', $product->is_active
            ? "Produk \"{$product->name}\" diaktifkan."
            : "Produk \"{$product->name}\" dinonaktifkan.");
    }

    /** @return array<string, mixed> */
    protected function productData(ProductRequest $request): array
    {
        $data = $request->safe()->except(['variants', 'variants_sync']);
        $data['category_id'] = $request->integer('category_id') ?: null;
        $data['sales_price'] = (int) ($data['sales_price'] ?? 0);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    /**
     * Sinkronkan baris varian dari form produk: baris bertanda id diperbarui,
     * baris tanpa id dibuat baru, varian yang tidak lagi dikirim dihapus.
     * Produk tanpa varian (form tanpa input "variants") tidak berubah.
     *
     * @param  array<int|string, array<string, mixed>|null>  $rows
     */
    protected function syncVariants(Product $product, array $rows): void
    {
        $keepIds = [];

        foreach ($rows as $row) {
            if (! is_array($row) || blank($row['name'] ?? null)) {
                continue; // baris kosong di form = tidak ada varian
            }

            $attributes = [
                'name' => $row['name'],
                'sku' => filled($row['sku'] ?? null) ? $row['sku'] : null,
                'sales_price' => (int) ($row['sales_price'] ?? 0),
                'is_active' => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
                // sort_order tidak dikirim form UI: pertahankan nilai lama agar
                // urutan varian tidak hilang setiap kali produk disimpan ulang.
                'sort_order' => $this->resolveSortOrder($product, $row),
            ];

            if (filled($row['id'] ?? null)) {
                $variant = $product->variants()->whereKey((int) $row['id'])->first();

                if ($variant) {
                    $variant->update($attributes);
                    $keepIds[] = $variant->id;

                    continue;
                }
            }

            $keepIds[] = $product->variants()->create($attributes)->id;
        }

        $product->variants()->whereKeyNot($keepIds)->delete();
    }

    /**
     * Tentukan sort_order varian: input eksplisit menang; bila tidak dikirim
     * (form UI), pertahankan nilai lama milik produk ini (default 0 untuk baru).
     *
     * @param  array<string, mixed>  $row
     */
    protected function resolveSortOrder(Product $product, array $row): int
    {
        if (array_key_exists('sort_order', $row) && $row['sort_order'] !== null && $row['sort_order'] !== '') {
            return (int) $row['sort_order'];
        }

        if (filled($row['id'] ?? null)) {
            $current = $product->variants()->whereKey((int) $row['id'])->value('sort_order');

            if ($current !== null) {
                return (int) $current;
            }
        }

        return 0;
    }

    /** @return Collection<int, ProductCategory> */
    protected function categories()
    {
        return ProductCategory::orderBy('sort_order')->orderBy('name')->get();
    }
}
