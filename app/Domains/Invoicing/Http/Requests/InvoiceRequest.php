<?php

namespace App\Domains\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            // Layanan harus milik klien yang dipilih (filter dropdown diperkuat di server).
            'service_id' => ['nullable', Rule::exists('services', 'id')->where('client_id', $this->input('client_id'))],
            'title' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Invoice minimal memiliki satu item.',
            'items.min' => 'Invoice minimal memiliki satu item.',
            'items.*.description.required' => 'Deskripsi setiap item wajib diisi.',
            'due_date.after_or_equal' => 'Tanggal jatuh tempo tidak boleh sebelum tanggal terbit.',
        ];
    }

    /**
     * Item yang dirender sebagai baris kosong (description blank) dibuang
     * sebelum validasi agar tombol "tambah baris" yang tidak dipakai
     * tidak menggagalkan submit.
     */
    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($item) => is_array($item) && filled($item['description'] ?? null))
            ->values()
            ->all();

        $this->merge(['items' => $items]);
    }
}
