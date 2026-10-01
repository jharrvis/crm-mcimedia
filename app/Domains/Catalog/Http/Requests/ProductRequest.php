<?php

namespace App\Domains\Catalog\Http\Requests;

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
            'description' => ['nullable', 'string'],
            'sales_price' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama produk wajib diisi.',
            'sku.unique' => 'SKU sudah dipakai produk lain.',
            'sales_price.integer' => 'Harga harus bilangan bulat (IDR).',
            'sales_price.min' => 'Harga tidak boleh negatif.',
        ];
    }

    /** SKU kosong dinormalkan menjadi null agar kolom unik nullable tidak bentrok. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => filled($this->input('sku')) ? trim((string) $this->input('sku')) : null,
        ]);
    }
}
