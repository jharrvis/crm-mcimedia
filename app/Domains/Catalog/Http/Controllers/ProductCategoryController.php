<?php

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Catalog\Http\Requests\ProductCategoryRequest;
use App\Domains\Catalog\Models\ProductCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProductCategoryController extends Controller
{
    public function index(): View
    {
        $categories = ProductCategory::withCount('products')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return view('product-categories.index', compact('categories'));
    }

    public function create(): View
    {
        return view('product-categories.create');
    }

    public function store(ProductCategoryRequest $request): RedirectResponse
    {
        $category = ProductCategory::create([
            ...$request->validated(),
            'slug' => $this->uniqueSlug($request->string('name')->toString()),
        ]);

        return redirect()->route('product-categories.index')
            ->with('success', "Kategori \"{$category->name}\" berhasil ditambahkan.");
    }

    public function edit(ProductCategory $productCategory): View
    {
        return view('product-categories.edit', ['category' => $productCategory]);
    }

    public function update(ProductCategoryRequest $request, ProductCategory $productCategory): RedirectResponse
    {
        $data = $request->validated();

        if ($data['name'] !== $productCategory->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $productCategory->id);
        }

        $productCategory->update($data);

        return redirect()->route('product-categories.index')
            ->with('success', "Kategori \"{$productCategory->name}\" berhasil diperbarui.");
    }

    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        $name = $productCategory->name;
        $count = $productCategory->products()->count();
        $productCategory->delete(); // produk menjadi tanpa kategori (FK nullOnDelete)

        $message = "Kategori \"{$name}\" dihapus.";
        if ($count > 0) {
            $message .= " {$count} produk menjadi tanpa kategori.";
        }

        return redirect()->route('product-categories.index')->with('success', $message);
    }

    protected function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'kategori';
        $slug = $base;
        $i = 2;

        while (ProductCategory::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
