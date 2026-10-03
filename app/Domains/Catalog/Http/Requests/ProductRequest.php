<?php

namespace App\Domains\Catalog\Http\Requests;

use App\Domains\Catalog\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Saat edit, route binding mengembalikan model Product; saat create nilainya null.
        $productId = $this->route('product')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'nullable', 'string', 'max:32',
                Rule::unique('products', 'sku')->ignore($productId),
            ],
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')],
            'description' => ['nullable', 'string'],
            'sales_price' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],

            // Varian opsional (UX-1); disinkronkan di ProductController::syncVariants().
            // Unik SKU varian dicek manual di withValidator (per-baris + DB-wide),
            // karena beberapa baris dikirim sekaligus dan id-nya di-ignore.
            'variants' => ['sometimes', 'array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['nullable', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:32'],
            'variants.*.sales_price' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** Varian milik produk ini saja; SKU varian unik global (DB-wide + antar-baris). */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $variants = (array) $this->input('variants', []);

            // 1) Semua id yang dikirim harus milik produk yang sedang diedit.
            $ids = collect($variants)->pluck('id')->filter(fn ($id) => filled($id))->map(fn ($id) => (int) $id)->all();
            if ($ids !== []) {
                $owned = ProductVariant::whereIn('id', $ids)
                    ->where('product_id', $this->route('product')?->id ?? 0)
                    ->count();

                if ($owned !== count($ids)) {
                    $v->errors()->add('variants', 'Ada varian yang bukan milik produk ini.');
                }
            }

            // 2) SKU unik antar-baris di dalam form yang sama.
            $skus = collect($variants)->pluck('sku')->filter();
            if ($skus->count() !== $skus->unique()->count()) {
                $v->errors()->add('variants', 'SKU varian tidak boleh sama antar baris.');
            }

            // 3) SKU unik terhadap DB (kecuali varian yang memang milik produk ini).
            $submittedIds = $ids;
            foreach (collect($variants)->pluck('sku')->filter() as $sku) {
                $conflict = ProductVariant::where('sku', $sku)
                    ->when($submittedIds !== [], fn ($q) => $q->whereNotIn('id', $submittedIds))
                    ->exists();

                if ($conflict) {
                    $v->errors()->add('variants', "SKU varian \"{$sku}\" sudah dipakai varian lain.");
                    break;
                }
            }

            // 4) Nama & harga harus berpasangan: baris varian yang sengaja diisi
            //    tidak boleh setengah; baris kosong (nama+harga kosong) diabaikan.
            foreach ($variants as $i => $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $hasName = filled($variant['name'] ?? null);
                $hasPrice = filled($variant['sales_price'] ?? null);
                $hasSku = filled($variant['sku'] ?? null);

                if ($hasName && ! $hasPrice) {
                    $v->errors()->add("variants.{$i}.sales_price", 'Harga varian wajib diisi.');
                }

                if (! $hasName && ($hasPrice || $hasSku)) {
                    $v->errors()->add("variants.{$i}.name", 'Nama varian wajib diisi.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama produk wajib diisi.',
            'sku.unique' => 'SKU sudah dipakai produk lain.',
            'sales_price.integer' => 'Harga harus bilangan bulat (IDR).',
            'sales_price.min' => 'Harga tidak boleh negatif.',
            'category_id.exists' => 'Kategori tidak valid.',
            'variants.*.name.required' => 'Nama varian wajib diisi.',
            'variants.*.sales_price.integer' => 'Harga varian harus bilangan bulat (IDR).',
            'variants.*.sales_price.min' => 'Harga varian tidak boleh negatif.',
        ];
    }

    /** SKU kosong dinormalkan menjadi null agar kolom unik nullable tidak bentrok. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => filled($this->input('sku')) ? trim((string) $this->input('sku')) : null,
        ]);

        if (! is_array($this->input('variants'))) {
            return;
        }

        $this->merge([
            'variants' => array_map(function ($variant) {
                if (! is_array($variant)) {
                    return $variant;
                }

                if (array_key_exists('sku', $variant)) {
                    $variant['sku'] = filled($variant['sku']) ? trim((string) $variant['sku']) : null;
                }

                if (array_key_exists('name', $variant)) {
                    $variant['name'] = filled($variant['name']) ? trim((string) $variant['name']) : null;
                }

                if (array_key_exists('sales_price', $variant) && ! is_numeric($variant['sales_price'])) {
                    $variant['sales_price'] = null;
                }

                return $variant;
            }, $this->input('variants')),
        ]);
    }
}
