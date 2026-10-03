<?php

namespace App\Domains\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Saat edit, route binding mengembalikan model ProductCategory.
        $categoryId = $this->route('product_category')?->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('product_categories', 'name')->ignore($categoryId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.unique' => 'Kategori dengan nama ini sudah ada.',
        ];
    }
}
